<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');

class SlackPlugin extends Plugin {

    var $config_class = "SlackPluginConfig";

    static $pluginInstance = null;

    private function getPluginInstance(?int $id) {
        if($id && ($i = $this->getInstance($id)))
            return $i;

        return $this->getInstances()->first();
    }

    function bootstrap() {
    
        self::$pluginInstance = self::getPluginInstance(null);

        $updateTypes = $this->getConfig(self::$pluginInstance)->get('slack-update-types');
        
        
        if($updateTypes == 'both' || $updateTypes == 'newOnly' || empty($updateTypes)) {
            Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        }
        
        if($updateTypes == 'both' || $updateTypes == 'updatesOnly' || empty($updateTypes)) {
            Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
        }

        //Captura el cambio de estado (panel de acción en osTicket))
        Signal::connect('object.edited', array($this, 'onObjectEdited'));
  
    }

    /*Caché simple para evitar duplicados (archivo temporal)
       key: "$ticketId|$eventKey"
       value: timestamp
       ventana por defecto: 3 segundos*/


    private function getCacheFilePath() {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ost_slack_notify_cache.json';
    }

    private function loadCache() {
        $file = $this->getCacheFilePath();
        if (!file_exists($file)) return [];
        $s = @file_get_contents($file);
        if (!$s) return [];
        $d = json_decode($s, true);
        return is_array($d) ? $d : [];
    }

    private function saveCache($data) {
        $file = $this->getCacheFilePath();
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            @file_put_contents($file, json_encode($data));
            return;
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    private function isRecentNotification($ticketId, $eventKey, $window = 3) {
        $cache = $this->loadCache();
        $k = $ticketId . '|' . $eventKey;
        $now = time();
        if (isset($cache[$k]) && ($now - $cache[$k]) < $window) {
            //para notificaciones recientes
            return true;
        }
        // registrar ahora
        $cache[$k] = $now;
        // limpiar entradas viejas
        foreach ($cache as $ck => $ts) {
            if (($now - $ts) > ($window * 10)) {
                unset($cache[$ck]);
            }
        }
        $this->saveCache($cache);
        return false;
    }

    /**
     * What to do with a new Ticket?
     * 
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @return type
     */
    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        
        // if slack-update-types is "updatesOnly", then don't send this!
        if($this->getConfig(self::$pluginInstance)->get('slack-update-types') == 'updatesOnly') {return;}

        // Evitar duplicados por si otro handler ya notificó
        $eventKey = 'created';
        if ($this->isRecentNotification($ticket->getId(), $eventKey)) {
            return;
        }

        // Convert any HTML in the message into text
        $plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());

        // Format the messages we'll send.
        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("New Ticket")
                , $cfg->getUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("created"));
        $this->sendToSlack($ticket, $heading, $plaintext);
    }

    /**
     * What to do with an Updated Ticket? (threadentry.created)
     * 
     * @global OsticketConfig $cfg
     * @param ThreadEntry $entry
     * @return type
     */
    function onTicketUpdated(ThreadEntry $entry) {
        global $cfg;

        if (!$cfg instanceof OsticketConfig) {
            error_log("Plugin Slack llamado demasiado pronto.");
            return;
        }

        if($this->getConfig(self::$pluginInstance)->get('slack-update-types') == 'newOnly') {
            return;
        }

        if (!$entry instanceof ThreadEntry) {
            return;
        }

        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) {
            return;
        }

        // Evita duplicados: ignorar el primer mensaje (ticket nuevo)
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            return;
        }

        $type = $entry->getType();

        /*SOLO mensajes de usuario / respuestas / notas
        Ignoramos los eventos del sistema (E), los maneja onObjectEdited*/


        if (in_array($type, array('M','R','N'))) {

            // Evitar duplicado por id de entry
            $eventKey = 'threadentry:' . $entry->getId();
            if ($this->isRecentNotification($ticket->getId(), $eventKey)) {
                return;
            }

            $plaintext = Format::html2text($entry->getBody()->getClean());

            $heading = sprintf(
                '%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s',
                __("Ticket"),
                $cfg->getUrl(),
                $ticket->getId(),
                $ticket->getNumber(),
                __("updated")
            );

            $this->sendToSlack($ticket, $heading, $plaintext, 'warning');
        }

    }

    /**
     * Dispara cuando se edita un objeto. La banderita de la lista usa esto.
     * @param mixed $object  El objeto afectado (Ticket, Task, etc.)
     * @param array $type    Info del evento, p.ej. ['type'=>'edited','key'=>'closed'|'reopened'|'status'...]
     */
    function onObjectEdited($object, $type=array()) {
        global $cfg;

        // Solo nos interesan tickets
        if (!$object instanceof Ticket) return;
        if (!$cfg instanceof OsticketConfig) return;

        $ticket = $object;

        // El segundo parámetro puede variar según contexto. Intentamos inferir si es edición de estado.
        $isEditArray = is_array($type);
        $key = null;
        if ($isEditArray && isset($type['type']) && $type['type'] !== 'edited') {
        }

        if ($isEditArray && isset($type['key'])) {
            $key = strtolower($type['key']);
        }

        // Detectar cambios típicos de la banderita (status):
        $detectedStatusChange = false;
        if ($isEditArray && ($key === 'closed' || $key === 'reopened' || $key === 'status' || $key === 'status_id')) {
            $detectedStatusChange = true;
        }

        // A veces 'status_id' viene directo en $type
        if ($isEditArray && isset($type['status_id'])) {
            $detectedStatusChange = true;
        }

        // Si no detectamos por $type: sale.
        if (!$detectedStatusChange) {
            return;
        }

        // Obtén el nombre del estado actual (nuevo)
        $status = $ticket->getStatus();
        $status_name = $status ? (method_exists($status,'getName') ? $status->getName() : (isset($status['name']) ? $status['name'] : '')) : __('updated');
        if (!$status_name) $status_name = __('updated');

        // Formar una clave para dedupe basada en el ticket y estado nuevo
        $eventKey = 'status:' . $status_name;

        if ($this->isRecentNotification($ticket->getId(), $eventKey)) {
            // ya notificado recientemente
            return;
        }

        $color = '#439FE0';
        if ($key === 'closed' || stripos($status_name, 'closed') !== false) {
            $color = 'danger';
        } elseif ($key === 'reopened' || stripos($status_name, 'reopen') !== false) {
            $color = 'good';
        } elseif ($key === 'status' || $key === 'status_id') {
            $color = 'warning';
        }

        $stateMessage = sprintf(__('status changed to %s'), $status_name);

        $heading = sprintf(
            '%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s',
            __("Ticket"),
            $cfg->getUrl(),
            $ticket->getId(),
            $ticket->getNumber(),
            $stateMessage
        );

        $text = $ticket->getSubject();

        //log para errores de duplicados
        error_log(sprintf('SlackPlugin.onObjectEdited -> ticket=%d key=%s status=%s color=%s', $ticket->getId(), $key, $status_name, $color));

        // Envía a Slack
        $this->sendToSlack($ticket, $heading, $text, $color);
    }

    /**
     * A helper function that sends messages to slack endpoints. 
     * 
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $heading
     * @param string $body
     * @param string $colour
     * @throws \Exception
     */
function sendToSlack(Ticket $ticket, $heading, $body, $colour = 'good') {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }

        $defaultUrl = $this->getConfig(self::$pluginInstance)->get('slack-webhook-url');

        $deptId = $ticket->getDeptId();

        switch ($deptId) {
            case 1:
                // Departamento de "Support"
                $webhookUrl = 'URL WEBHOOK';
                break;

            case 2:
                // Departamento de "Sales"
                $webhookUrl = 'URL WEBHOOK';
                break;
            
            case 3:
                // Departamento de "Maintenance"
                $webhookUrl = 'URL WEBHOOK';
                break;

            default:
                // Para cualquier otro departamento, usamos la URL general configurada en el panel.
                $webhookUrl = $defaultUrl;
                break;
        }

        // 4. Verificamos si, al final, tenemos una URL válida antes de continuar.
        if (!$webhookUrl) {
            $ost->logError('Slack Plugin not configured', 'You need to configure a default webhook URL and/or a specific URL for the department with ID: ' . $deptId);
            return; // Detenemos la ejecución si no hay URL.
        }

        // Check the subject, see if we want to filter it.
        $regex_subject_ignore = $this->getConfig(self::$pluginInstance)->get('slack-regex-subject-ignore');
        // Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            $ost->logDebug('Ignored Message', 'Slack notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            return;
        }

        $heading = $this->format_text($heading);

        // Pull template from config, and use that. 
        $template          = $this->getConfig(self::$pluginInstance)->get('message-template');
        // Add our custom var
        $custom_vars       = [
            'slack_safe_message' => $this->format_text($body),
        ];
        $formatted_message = $ticket->replaceVars($template, $custom_vars);

        // Formato del mensaje para Slack.
        $payload['attachments'][0] = [
            'pretext'     => $heading,
            'fallback'    => $heading,
            'color'       => $colour,
            'author'      => $ticket->getOwner(),
            'title'       => $ticket->getSubject(),
            'title_link'  => $cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId(),
            'ts'          => time(),
            'footer'      => 'via osTicket Slack Plugin',
            'footer_icon' => 'https://platform.slack-edge.com/img/default_application_icon.png',
            'text'        => $formatted_message,
            'mrkdwn_in'   => ["text"]
        ];

        // Add a field for tasks if there are open ones
        if ($ticket->getNumOpenTasks()) {
            $payload['attachments'][0]['fields'][] = [
                'title' => __('Open Tasks'),
                'value' => $ticket->getNumOpenTasks(),
                'short' => TRUE,
            ];
        }
        // Change the colour to Fuschia if ticket is overdue
        if ($ticket->isOverdue()) {
            $payload['attachments'][0]['colour'] = '#ff00ff';
        }

        // Format the payload:
        $data_string = utf8_encode(json_encode($payload));

        try {
            // Setup curl - ¡USAMOS NUESTRA VARIABLE $webhookUrl!
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
            );

            // Actually send the payload to slack:
            if (curl_exec($ch) === false) {
                throw new \Exception($webhookUrl . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') {
                    throw new \Exception(
                    'Error sending to: ' . $webhookUrl
                    . ' Http code: ' . $statusCode
                    . ' curl-error: ' . curl_errno($ch));
                }
            }
        } catch (\Exception $e) {
            $ost->logError('Slack posting issue!', $e->getMessage(), true);
            error_log('Error posting to Slack. ' . $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry        	
     * @return Ticket
     */

    function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter([
                    'id' => $entry->getThreadId()
                ])->values_flat('object_id')->first() [0];

        // Force lookup rather than use cached data.. 
        return Ticket::lookup(array(
                    'ticket_id' => $ticket_id
        ));
    }

    /**
     * Formats text according to the 
     * formatting rules:https://api.slack.com/docs/message-formatting
     * 
     * @param string $text
     * @return string
     */

    function format_text($text) {
        $formatter      = [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;'
        ];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        // put the <>'s control characters back in
        $moreformatter  = [
            'CONTROLSTART' => '<',
            'CONTROLEND'   => '>'
        ];
        // Replace the CONTROL characters, and limit text length to 500 characters.
        return mb_substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source https://gravatar.com/site/implement/images/php/
     */
    
    function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array()) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

}
