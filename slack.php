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
            return true;
        }
        $cache[$k] = $now;
        foreach ($cache as $ck => $ts) {
            if (($now - $ts) > ($window * 10)) {
                unset($cache[$ck]);
            }
        }
        $this->saveCache($cache);
        return false;
    }

    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }
        
        if($this->getConfig(self::$pluginInstance)->get('slack-update-types') == 'updatesOnly') {return;}

        $eventKey = 'created';
        if ($this->isRecentNotification($ticket->getId(), $eventKey)) {
            return;
        }

        $plaintext = Format::html2text($ticket->getMessages()[0]->getBody()->getClean());
        $heading = sprintf('%s CONTROLSTART%sscp/tickets.php?id=%d|#%sCONTROLEND %s'
                , __("New Ticket")
                , $cfg->getUrl()
                , $ticket->getId()
                , $ticket->getNumber()
                , __("created"));
        $this->sendToSlack($ticket, $heading, $plaintext, '#000000');
    }

    function onTicketUpdated(ThreadEntry $entry) {
        global $cfg;

        if (!$cfg instanceof OsticketConfig) { return; }

        if($this->getConfig(self::$pluginInstance)->get('slack-update-types') == 'newOnly') { return; }

        if (!$entry instanceof ThreadEntry) { return; }

        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) { return; }

        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) { return; }

        if (in_array($entry->getType(), array('M','R','N'))) {

            $eventKey = 'threadentry:' . $entry->getId();
            if ($this->isRecentNotification($ticket->getId(), $eventKey)) { return; }

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

    function onObjectEdited($object, $type=array()) {
        global $cfg;

        if (!$object instanceof Ticket) return;
        if (!$cfg instanceof OsticketConfig) return;

        $ticket = $object;
        $key = isset($type['key']) ? strtolower($type['key']) : null;

        if (!in_array($key, ['closed', 'reopened', 'status', 'status_id'])) return;

        $status = $ticket->getStatus();
        $status_name = $status ? $status->getName() : __('updated');
        $eventKey = 'status:' . $status_name;

        if ($this->isRecentNotification($ticket->getId(), $eventKey)) { return; }

        // --- INICIO: LÓGICA PARA ELEGIR FORMATO ---
        if ($key === 'closed' || stripos($status_name, 'closed') !== false || stripos($status_name, 'resolved') !== false) {
            // Es un ticket cerrado, usamos la notificación especial simplificada.
            $this->sendToSlack($ticket, '', '', 'good', 'closed');
        } else {
            // Es otro cambio de estado (reabierto, en progreso, etc.), usamos el formato completo.
            $color = 'warning';
            if ($key === 'reopened' || stripos($status_name, 'reopen') !== false) {
                $color = '#000000';
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
    
            $this->sendToSlack($ticket, $heading, $ticket->getSubject(), $color);
        }
        // --- FIN: LÓGICA PARA ELEGIR FORMATO ---
    }

    function sendToSlack(Ticket $ticket, $heading, $body, $colour = 'good', $messageType = 'default') {
        global $ost, $cfg;
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Slack plugin called too early.");
            return;
        }

        $deptId = $ticket->getDeptId();
        $webhookUrl = '';

        switch ($deptId) {
            case 1: $webhookUrl = ''; break;
            case 2: $webhookUrl = ''; break;
            case 3: $webhookUrl = ''; break;
            default: return;
        }

        if (!$webhookUrl) { return; }

        $regex_subject_ignore = $this->getConfig(self::$pluginInstance)->get('slack-regex-subject-ignore');
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            return;
        }

        $payload = [];

        if ($messageType === 'closed') {
            // Formato simplificado para tickets cerrados
            $status_name = $ticket->getStatus()->getName();
            $payload['attachments'][0] = [
                'color'       => $colour,
                'fallback'    => sprintf('Ticket #%s  %s', $ticket->getNumber(), $ticket->getSubject()),
                'title'       => sprintf('#%s: %s', $ticket->getNumber(), $ticket->getSubject()),
                'title_link'  => $cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId(),
                'text'        => sprintf('Estado: *%s*', $status_name),
                'ts'          => time(),
                'footer'      => 'via osTicket Slack Plugin',
            ];
        } else {
            // Formato completo para todas las demás notificaciones
            $heading = $this->format_text($heading);
            $template = $this->getConfig(self::$pluginInstance)->get('message-template');
            $custom_vars = ['slack_safe_message' => $this->format_text($body)];
            $formatted_message = $ticket->replaceVars($template, $custom_vars);

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
        }
        // --- FIN: LÓGICA DE DOBLE FORMATO ---

        if ($ticket->isOverdue()) {
            $payload['attachments'][0]['color'] = '#ff00ff';
        }

        $data_string = utf8_encode(json_encode($payload));

        try {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($data_string)));
            
            if (curl_exec($ch) === false) throw new \Exception($webhookUrl . ' - ' . curl_error($ch));
            else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') throw new \Exception('Error sending to: ' . $webhookUrl . ' Http code: ' . $statusCode);
            }
        } catch (\Exception $e) {
            $ost->logError('Slack posting issue!', $e->getMessage(), true);
        } finally {
            if(isset($ch)) curl_close($ch);
        }
    }

    function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter(['id' => $entry->getThreadId()])->values_flat('object_id')->first()[0];
        return Ticket::lookup(['ticket_id' => $ticket_id]);
    }

    function format_text($text) {
        $formatter = ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;'];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        $moreformatter = ['CONTROLSTART' => '<', 'CONTROLEND' => '>'];
        return mb_substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

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
