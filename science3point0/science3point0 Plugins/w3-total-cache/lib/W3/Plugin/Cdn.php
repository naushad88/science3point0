<?php

/**
 * W3 Total Cache CDN Plugin
 */
require_once W3TC_LIB_W3_DIR . '/Plugin.php';

/**
 * Class W3_Plugin_Cdn
 */
class W3_Plugin_Cdn extends W3_Plugin
{
    /**
     * Array of replaced URLs
     *
     * @var array
     */
    var $replaced_urls = array();
    
    /**
     * CDN reject reason
     *
     * @var string
     */
    var $cdn_reject_reason = '';
    
    /**
     * Run plugin
     */
    function run()
    {
        register_activation_hook(W3TC_FILE, array(
            &$this, 
            'activate'
        ));
        
        register_deactivation_hook(W3TC_FILE, array(
            &$this, 
            'deactivate'
        ));
        
        add_filter('cron_schedules', array(
            &$this, 
            'cron_schedules'
        ));
        
        if ($this->_config->get_boolean('cdn.enabled')) {
            if (!in_array($this->_config->get_string('cdn.engine'), array(
                'mirror', 
                'netdna'
            ))) {
                add_action('add_attachment', array(
                    &$this, 
                    'add_attachment'
                ));
                
                add_action('delete_attachment', array(
                    &$this, 
                    'delete_attachment'
                ));
                
                add_filter('wp_generate_attachment_metadata', array(
                    &$this, 
                    'generate_attachment_metadata'
                ));
                
                add_action('w3_cdn_cron_queue_process', array(
                    &$this, 
                    'cron_queue_process'
                ));
                
                add_action('w3_cdn_cron_upload', array(
                    &$this, 
                    'cron_upload'
                ));
                
                add_action('switch_theme', array(
                    &$this, 
                    'switch_theme'
                ));
                
                add_filter('update_feedback', array(
                    &$this, 
                    'update_feedback'
                ));
            }
            
            if ($this->can_cdn()) {
                ob_start(array(
                    &$this, 
                    'ob_callback'
                ));
            }
        }
    }
    
    /**
     * Returns plugin instance
     *
     * @return W3_Plugin_Cdn
     */
    function &instance()
    {
        static $instances = array();
        
        if (!isset($instances[0])) {
            $class = __CLASS__;
            $instances[0] = & new $class();
        }
        
        return $instances[0];
    }
    
    /**
     * Activation action
     */
    function activate()
    {
        global $wpdb;
        
        $upload_info = w3_upload_info();
        
        if (!$upload_info) {
            $upload_path = get_option('upload_path');
            $upload_path = trim($upload_path);
            
            if (empty($upload_path)) {
                echo 'Your store uploads folder is not available. Default WordPress directories will be created: <strong>wp-content/uploads/</strong>.<br />';
                $upload_path = WP_CONTENT_DIR . '/uploads';
            }
            
            w3_writable_error($upload_path);
        }
        
        $sql = sprintf('DROP TABLE IF EXISTS `%s%s`', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        
        $wpdb->query($sql);
        
        $sql = sprintf("CREATE TABLE IF NOT EXISTS `%s%s` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `local_path` varchar(150) NOT NULL DEFAULT '',
            `remote_path` varchar(150) NOT NULL DEFAULT '',
            `command` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '1 - Upload, 2 - Delete',
            `last_error` varchar(150) NOT NULL DEFAULT '',
            `date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (`id`),
            UNIQUE KEY `path` (`local_path`, `remote_path`),
            KEY `date` (`date`)
        ) /*!40100 CHARACTER SET latin1 */", $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        
        $wpdb->query($sql);
        
        if (!$wpdb->result) {
            $error = sprintf('Unable to create table <strong>%s%s</strong>: %s', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE, $wpdb->last_error);
            
            w3_activate_error($error);
        }
        
        $this->schedule();
        $this->schedule_upload();
    }
    
    /**
     * Deactivation action
     */
    function deactivate()
    {
        global $wpdb;
        
        $this->unschedule_upload();
        $this->unschedule();
        
        $sql = sprintf('DROP TABLE IF EXISTS `%s%s`', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        $wpdb->query($sql);
    }
    
    /**
     * Schedules cron events
     */
    function schedule()
    {
        if ($this->_config->get_boolean('cdn.enabled') && !in_array($this->_config->get_string('cdn.engine'), array(
            'mirror', 
            'netdna'
        ))) {
            if (!wp_next_scheduled('w3_cdn_cron_queue_process')) {
                wp_schedule_event(time(), 'w3_cdn_cron_queue_process', 'w3_cdn_cron_queue_process');
            }
        } else {
            $this->unschedule();
        }
    }
    
    /**
     * Schedule upload event
     */
    function schedule_upload()
    {
        if ($this->_config->get_boolean('cdn.enabled') && $this->_config->get_boolean('cdn.autoupload.enabled') && !in_array($this->_config->get_string('cdn.engine'), array(
            'mirror', 
            'netdna'
        ))) {
            if (!wp_next_scheduled('w3_cdn_cron_upload')) {
                wp_schedule_event(time(), 'w3_cdn_cron_upload', 'w3_cdn_cron_upload');
            }
        } else {
            $this->unschedule_upload();
        }
    }
    
    /**
     * Unschedules cron events
     */
    function unschedule()
    {
        if (wp_next_scheduled('w3_cdn_cron_queue_process')) {
            wp_clear_scheduled_hook('w3_cdn_cron_queue_process');
        }
    }
    
    /**
     * Unschedule upload event
     */
    function unschedule_upload()
    {
        if (wp_next_scheduled('w3_cdn_cron_upload')) {
            wp_clear_scheduled_hook('w3_cdn_cron_upload');
        }
    }
    
    /**
     * Cron queue process event
     */
    function cron_queue_process()
    {
        $queue_limit = $this->_config->get_integer('cdn.queue.limit');
        $this->queue_process($queue_limit);
    }
    
    /**
     * Cron upload event
     */
    function cron_upload()
    {
        $files = $this->get_files();
        $site_path = ltrim(w3_get_site_path(), '/');
        
        $upload = array();
        $results = array();
        
        foreach ($files as $file) {
            $upload[ABSPATH . $file] = $site_path . $file;
        }
        
        $this->upload($upload, true, $results);
    }
    
    /**
     * On attachment add action
     *
     * @param integer $attachment_id
     */
    function add_attachment($attachment_id)
    {
        $files = $this->get_attachment_files($attachment_id);
        $files = apply_filters('w3tc_cdn_add_attachment', $files);
        
        $results = array();
        
        $this->upload($files, true, $results);
    }
    
    /**
     * Generate attachment metadata filter
     *
     * @param array $metadata
     * @return array
     */
    function generate_attachment_metadata($metadata)
    {
        $files = $this->get_metadata_files($metadata);
        $files = apply_filters('w3tc_cdn_generate_attachment_metadata', $files);
        
        $results = array();
        
        $this->upload($files, true, $results);
        
        return $metadata;
    }
    
    /**
     * On attachment delete action
     *
     * @param integer $attachment_id
     */
    function delete_attachment($attachment_id)
    {
        $files = $this->get_attachment_files($attachment_id);
        $files = apply_filters('w3tc_cdn_delete_attachment', $files);
        
        $results = array();
        
        $this->delete($files, true, $results);
    }
    
    /**
     * Purge attachment
     * 
     * @param integer $attachment_id
     * @return boolean
     */
    function purge_attachment($attachment_id)
    {
        $files = $this->get_attachment_files($attachment_id);
        $results = array();
        
        if ($this->_config->get_string('cdn.engine') == 'netdna') {
            $queue_failed = false;
        } else {
            $queue_failed = true;
        }
        
        return $this->purge($files, $queue_failed, $results);
    }
    
    /**
     * Cron schedules filter
     *
     * @paran array $schedules
     * @return array
     */
    function cron_schedules($schedules)
    {
        $interval = $this->_config->get_integer('cdn.autoupload.interval');
        
        return array_merge($schedules, array(
            'w3_cdn_cron_queue_process' => array(
                'interval' => 900, 
                'display' => '[W3TC] CDN queue process (every 15 minutes)'
            ), 
            'w3_cdn_cron_upload' => array(
                'interval' => $interval, 
                'display' => sprintf('[W3TC] CDN auto upload (every %d seconds)', $interval)
            )
        ));
    }
    
    /**
     * Switch theme action
     */
    function switch_theme()
    {
        $this->_config->set('notes.theme_changed', true);
        $this->_config->save();
    }
    
    /**
     * WP Upgrade action hack
     *
     * @param string $message
     */
    function update_feedback($message)
    {
        if ($message == __('Upgrading database')) {
            $this->_config->set('notes.wp_upgraded', true);
            $this->_config->save();
        }
    }
    
    /**
     * Returns attachment files by attachment ID
     *
     * @param integer $attachment_id
     * @return array
     */
    function get_attachment_files($attachment_id)
    {
        $files = array();
        $upload_info = w3_upload_info();
        
        if ($upload_info) {
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            $attachment_metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
            
            if ($attached_file) {
                $file = $this->normalize_attachment_file($attached_file);
                
                $local_file = $upload_info['basedir'] . '/' . $file;
                $remote_file = ltrim($upload_info['baseurlpath'] . $file, '/');
                
                $files[$local_file] = $remote_file;
            }
            
            if ($attachment_metadata) {
                $files = array_merge($files, $this->get_metadata_files($attachment_metadata));
            }
        }
        return $files;
    }
    
    /**
     * OB Callback
     *
     * @param string $buffer
     * @return string
     */
    function ob_callback(&$buffer)
    {
        if ($buffer != '' && w3_is_xml($buffer)) {
            if ($this->can_cdn2($buffer)) {
                $regexps = array();
                $site_path = w3_get_site_path();
                $upload_info = w3_upload_info();
                $domain_url_regexp = w3_get_domain_url_regexp();
                
                if ($upload_info) {
                    if (preg_match('~' . $domain_url_regexp . '~i', $upload_info['baseurl'])) {
                        $regexps[] = '~(["\'])((' . $domain_url_regexp . ')?(' . w3_preg_quote($upload_info['baseurlpath']) . '([^"\'>]+)))~';
                    } else {
                        $regexps[] = '~(["\'])((' . w3_preg_quote($upload_info['baseurl']) . ')(([^"\'>]+)))~';
                    }
                }
                
                if ($this->_config->get_boolean('cdn.includes.enable')) {
                    $mask = $this->_config->get_string('cdn.includes.files');
                    if ($mask != '') {
                        $regexps[] = '~(["\'])((' . $domain_url_regexp . ')?(' . w3_preg_quote($site_path . WPINC) . '/(' . $this->get_regexp_by_mask($mask) . ')))~';
                    }
                }
                
                if ($this->_config->get_boolean('cdn.theme.enable')) {
                    $theme_dir = preg_replace('~' . $domain_url_regexp . '~i', '', get_theme_root_uri());
                    
                    $mask = $this->_config->get_string('cdn.theme.files');
                    
                    if ($mask != '') {
                        $regexps[] = '~(["\'])((' . $domain_url_regexp . ')?(' . w3_preg_quote($theme_dir) . '/(' . $this->get_regexp_by_mask($mask) . ')))~';
                    }
                }
                
                if ($this->_config->get_boolean('cdn.minify.enable')) {
                    $regexps[] = '~(["\'])((' . $domain_url_regexp . ')?(' . w3_preg_quote($site_path . W3TC_CONTENT_MINIFY_DIR_NAME) . '/[a-f0-9]+/.+\.include(-(footer|body))?(-nb)?\.[0-9]+\.(css|js)))~U';
                }
                
                if ($this->_config->get_boolean('cdn.custom.enable')) {
                    $masks = $this->_config->get_array('cdn.custom.files');
                    
                    if (count($masks)) {
                        $mask_regexps = array();
                        
                        foreach ($masks as $mask) {
                            if ($mask != '') {
                                $mask = w3_normalize_file($mask);
                                $mask_regexps[] = $this->get_regexp_by_mask($mask);
                            }
                        }
                        
                        $regexps[] = '~(["\'])((' . $domain_url_regexp . ')?(' . w3_preg_quote($site_path) . '(' . implode('|', $mask_regexps) . ')))~i';
                    }
                }
                
                foreach ($regexps as $regexp) {
                    $buffer = preg_replace_callback($regexp, array(
                        &$this, 
                        'link_replace_callback'
                    ), $buffer);
                }
            }
            
            if ($this->_config->get_boolean('cdn.debug')) {
                $buffer .= "\r\n\r\n" . $this->get_debug_info();
            }
        }
        
        return $buffer;
    }
    
    /**
     * Returns attachment files by metadata
     *
     * @param array $metadata
     * @return array
     */
    function get_metadata_files($metadata)
    {
        $files = array();
        
        $upload_info = w3_upload_info();
        
        if ($upload_info) {
            if (isset($metadata['file'])) {
                $file = $this->normalize_attachment_file($metadata['file']);
                
                if (isset($metadata['sizes'])) {
                    $file_dir = w3_dirname($file);
                    
                    if ($file_dir) {
                        $file_dir .= '/';
                    }
                    
                    foreach ((array) $metadata['sizes'] as $size) {
                        if (isset($size['file'])) {
                            $local_file = $upload_info['basedir'] . '/' . $file_dir . $size['file'];
                            $remote_file = ltrim($upload_info['baseurlpath'] . $file_dir . $size['file'], '/');
                            
                            $files[$local_file] = $remote_file;
                        }
                    }
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Adds file to queue
     *
     * @param string $local_path
     * @param string $remote_path
     * @param integer $command
     * @param string $last_error
     * @return ingteer
     */
    function queue_add($local_path, $remote_path, $command, $last_error)
    {
        global $wpdb;
        
        $table = $wpdb->prefix . W3TC_CDN_TABLE_QUEUE;
        $sql = sprintf('SELECT id FROM %s WHERE local_path = "%s" AND remote_path = "%s" AND command != %d', $table, $wpdb->escape($local_path), $wpdb->escape($remote_path), $command);
        
        if (($row = $wpdb->get_row($sql))) {
            $sql = sprintf('DELETE FROM %s WHERE id = %d', $table, $row->id);
        } else {
            $sql = sprintf('REPLACE INTO %s (local_path, remote_path, command, last_error, date) VALUES ("%s", "%s", %d, "%s", NOW())', $table, $wpdb->escape($local_path), $wpdb->escape($remote_path), $command, $wpdb->escape($last_error));
        }
        
        return $wpdb->query($sql);
    }
    
    /**
     * Updates file date in the queue
     *
     * @param integer $queue_id
     * @param string $last_error
     * @return integer
     */
    function queue_update($queue_id, $last_error)
    {
        global $wpdb;
        
        $sql = sprintf('UPDATE %s SET last_error = "%s", date = NOW() WHERE id = %d', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE, $wpdb->escape($last_error), $queue_id);
        
        return $wpdb->query($sql);
    }
    
    /**
     * Removes from queue
     *
     * @param integer $queue_id
     * @return integer
     */
    function queue_delete($queue_id)
    {
        global $wpdb;
        
        $sql = sprintf('DELETE FROM %s WHERE id = %d', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE, $queue_id);
        
        return $wpdb->query($sql);
    }
    
    /**
     * Empties queue
     *
     * @param integer $command
     * @return integer
     */
    function queue_empty($command)
    {
        global $wpdb;
        
        $sql = sprintf('DELETE FROM %s WHERE command = %d', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE, $command);
        
        return $wpdb->query($sql);
    }
    
    /**
     * Returns queue
     *
     * @param integer $limit
     * @return array
     */
    function queue_get($limit = null)
    {
        global $wpdb;
        
        $sql = sprintf('SELECT * FROM %s%s ORDER BY date', $wpdb->prefix, W3TC_CDN_TABLE_QUEUE);
        
        if ($limit) {
            $sql .= sprintf(' LIMIT %d', $limit);
        }
        
        $results = $wpdb->get_results($sql);
        $queue = array();
        
        if ($results) {
            foreach ((array) $results as $result) {
                $queue[$result->command][] = $result;
            }
        }
        
        return $queue;
    }
    
    /**
     * Process queue
     *
     * @param integer $limit
     */
    function queue_process($limit)
    {
        $commands = $this->queue_get($limit);
        $force_rewrite = $this->_config->get_boolean('cdn.force.rewrite');
        
        if (count($commands)) {
            $cdn = & $this->get_cdn();
            
            foreach ($commands as $command => $queue) {
                $files = array();
                $results = array();
                $map = array();
                
                foreach ($queue as $result) {
                    $files[$result->local_path] = $result->remote_path;
                    $map[$result->local_path] = $result->id;
                }
                
                switch ($command) {
                    case W3TC_CDN_COMMAND_UPLOAD:
                        $cdn->upload($files, $results, $force_rewrite);
                        break;
                    
                    case W3TC_CDN_COMMAND_DELETE:
                        $cdn->delete($files, $results);
                        break;
                }
                
                foreach ($results as $result) {
                    if ($result['result'] == W3TC_CDN_RESULT_OK) {
                        $this->queue_delete($map[$result['local_path']]);
                    } else {
                        $this->queue_update($map[$result['local_path']], $result['error']);
                    }
                }
            }
        }
    }
    
    /**
     * Uploads files to CDN
     *
     * @param array $files
     * @param boolean $queue_failed
     * @param array $results
     * @return boolean
     */
    function upload($files, $queue_failed, &$results)
    {
        $cdn = & $this->get_cdn();
        $force_rewrite = $this->_config->get_boolean('cdn.force.rewrite');
        
        @set_time_limit(600);
        
        if (!$cdn->upload($files, $results, $force_rewrite)) {
            if ($queue_failed) {
                foreach ($results as $result) {
                    if ($result['result'] != W3TC_CDN_RESULT_OK) {
                        $this->queue_add($result['local_path'], $result['remote_path'], W3TC_CDN_COMMAND_UPLOAD, $result['error']);
                    }
                }
            }
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Deletes files frrom CDN
     *
     * @param array $files
     * @param boolean $queue_failed
     * @param array $results
     * @return boolean
     */
    function delete($files, $queue_failed, &$results)
    {
        $cdn = & $this->get_cdn();
        
        @set_time_limit(600);
        
        if (!$cdn->delete($files, $results)) {
            if ($queue_failed) {
                foreach ($results as $result) {
                    if ($result['result'] != W3TC_CDN_RESULT_OK) {
                        $this->queue_add($result['local_path'], $result['remote_path'], W3TC_CDN_COMMAND_DELETE, $result['error']);
                    }
                }
            }
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Purges files from CDN
     *
     * @param array $files
     * @param boolean $queue_failed
     * @param array $results
     * @return boolean
     */
    function purge($files, $queue_failed, &$results)
    {
        $cdn = & $this->get_cdn();
        
        @set_time_limit(600);
        
        if (!$cdn->purge($files, $results)) {
            if ($queue_failed) {
                foreach ($results as $result) {
                    if ($result['result'] != W3TC_CDN_RESULT_OK) {
                        $this->queue_add($result['local_path'], $result['remote_path'], W3TC_CDN_COMMAND_UPLOAD, $result['error']);
                    }
                }
            }
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Export library to CDN
     *
     * @param integer $limit
     * @param integer $offset
     * @param integer $count
     * @param integer $total
     * @param array $results
     * @return boolean
     */
    function export_library($limit, $offset, &$count, &$total, &$results)
    {
        global $wpdb;
        
        $count = 0;
        $total = 0;
        
        $upload_info = w3_upload_info();
        
        if ($upload_info) {
            $sql = sprintf('SELECT
        		pm.meta_value AS file,
                pm2.meta_value AS metadata
            FROM
                %sposts AS p
            LEFT JOIN
                %spostmeta AS pm ON p.ID = pm.post_ID AND pm.meta_key = "_wp_attached_file"
            LEFT JOIN
            	%spostmeta AS pm2 ON p.ID = pm2.post_ID AND pm2.meta_key = "_wp_attachment_metadata"
            WHERE
                p.post_type = "attachment"
            GROUP BY
            	p.ID', $wpdb->prefix, $wpdb->prefix, $wpdb->prefix);
            
            if ($limit) {
                $sql .= sprintf(' LIMIT %d', $limit);
                
                if ($offset) {
                    $sql .= sprintf(' OFFSET %d', $offset);
                }
            }
            
            $posts = $wpdb->get_results($sql);
            
            if ($posts) {
                $count = count($posts);
                $total = $this->get_attachments_count();
                $files = array();
                
                foreach ($posts as $post) {
                    $post_files = array();
                    
                    if ($post->file) {
                        $file = $this->normalize_attachment_file($post->file);
                        
                        $local_file = $upload_info['basedir'] . '/' . $file;
                        $remote_file = ltrim($upload_info['baseurlpath'] . $file, '/');
                        
                        $post_files[$local_file] = $remote_file;
                    }
                    
                    if ($post->metadata) {
                        $metadata = @unserialize($post->metadata);
                        
                        $post_files = array_merge($post_files, $this->get_metadata_files($metadata));
                    }
                    
                    $post_files = apply_filters('w3tc_cdn_add_attachment', $post_files);
                    
                    $files = array_merge($files, $post_files);
                }
                
                return $this->upload($files, false, $results);
            }
        }
        
        return false;
    }
    
    /**
     * Imports library
     *
     * @param integer $limit
     * @param integer $offset
     * @param integer $count
     * @param integer $total
     * @param array $results
     * @return boolean
     */
    function import_library($limit, $offset, &$count, &$total, &$results)
    {
        global $wpdb;
        
        $count = 0;
        $total = 0;
        $results = array();
        
        $upload_info = w3_upload_info();
        
        @set_time_limit(300);
        
        if ($upload_info) {
            /**
             * Search for posts with links or images
             */
            $sql = sprintf('SELECT
        		ID,
        		post_content,
        		post_date
            FROM
                %sposts
            WHERE
                post_status = "publish"
                AND (post_type = "post" OR post_type = "page")
                AND (post_content LIKE "%%src=%%"
                	OR post_content LIKE "%%href=%%")
       		', $wpdb->prefix);
            
            if ($limit) {
                $sql .= sprintf(' LIMIT %d', $limit);
                
                if ($offset) {
                    $sql .= sprintf(' OFFSET %d', $offset);
                }
            }
            
            $posts = $wpdb->get_results($sql);
            
            if ($posts) {
                $count = count($posts);
                $total = $this->get_import_posts_count();
                $regexp = '~(' . $this->get_regexp_by_mask($this->_config->get_string('cdn.import.files')) . ')$~';
                $import_external = $this->_config->get_boolean('cdn.import.external');
                
                foreach ($posts as $post) {
                    $matches = null;
                    $replaced = array();
                    $attachments = array();
                    $post_content = $post->post_content;
                    
                    /**
                     * Search for all link and image sources
                     */
                    if (preg_match_all('~(href|src)=[\'"]?([^\'"<>\s]+)[\'"]?~', $post_content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            list($search, $attribute, $origin) = $match;
                            
                            /**
                             * Check if $search is already replaced
                             */
                            if (isset($replaced[$search])) {
                                continue;
                            }
                            
                            $error = '';
                            $result = false;
                            
                            $src = w3_normalize_file($origin);
                            $dst = '';
                            
                            /**
                             * Check if file exists in the library
                             */
                            if (stristr($origin, $upload_info['baseurl']) === false) {
                                /**
                                 * Check file extension
                                 */
                                if (preg_match($regexp, $src)) {
                                    /**
                                     * Check for alredy uploaded attachment
                                     */
                                    if (isset($attachments[$src])) {
                                        list($dst, $dst_url) = $attachments[$src];
                                        $result = true;
                                    } else {
                                        $upload_subdir = date('Y/m', strtotime($post->post_date));
                                        $upload_dir = sprintf('%s/%s', $upload_info['basedir'], $upload_subdir);
                                        $upload_url = sprintf('%s/%s', $upload_info['baseurl'], $upload_subdir);
                                        
                                        $src_filename = pathinfo($src, PATHINFO_FILENAME);
                                        $src_extension = pathinfo($src, PATHINFO_EXTENSION);
                                        
                                        /**
                                         * Get available filename
                                         */
                                        for ($i = 0;; $i++) {
                                            $dst = sprintf('%s/%s%s%s', $upload_dir, $src_filename, ($i ? $i : ''), ($src_extension ? '.' . $src_extension : ''));
                                            
                                            if (!file_exists($dst)) {
                                                break;
                                            }
                                        }
                                        
                                        $dst_basename = basename($dst);
                                        $dst_dirname = str_replace($upload_dir, '', $upload_info['basedir']);
                                        $dst_url = sprintf('%s/%s', $upload_url, $dst_basename);
                                        
                                        w3_mkdir($dst_dirname, 0755, $upload_info['basedir']);
                                        
                                        $download_result = false;
                                        
                                        /**
                                         * Check if file is remote URL
                                         */
                                        if (w3_is_url($src)) {
                                            /**
                                             * Download file
                                             */
                                            if ($import_external) {
                                                $download_result = w3_download($src, $dst);
                                                
                                                if (!$download_result) {
                                                    $error = 'Unable to download file';
                                                }
                                            } else {
                                                $error = 'External file import is disabled';
                                            }
                                        } else {
                                            /**
                                             * Otherwise copy file from local path
                                             */
                                            $src_path = w3_get_document_root() . urldecode($src);
                                            
                                            if (file_exists($src_path)) {
                                                $download_result = @copy($src_path, $dst);
                                                
                                                if (!$download_result) {
                                                    $error = 'Unable to copy file';
                                                }
                                            } else {
                                                $error = 'Source file doesn\'t exists';
                                            }
                                        }
                                        
                                        /**
                                         * Check if download or copy was successful
                                         */
                                        if ($download_result) {
                                            $title = $dst_basename;
                                            $guid = ltrim($upload_info['baseurlpath'] . $title, ',');
                                            $mime_type = w3_get_mime_type($dst_basename);
                                            
                                            $GLOBALS['wp_rewrite'] = & new WP_Rewrite();
                                            
                                            /**
                                             * Insert attachment
                                             */
                                            $id = wp_insert_attachment(array(
                                                'post_mime_type' => $mime_type, 
                                                'guid' => $guid, 
                                                'post_title' => $title, 
                                                'post_content' => ''
                                            ), $dst);
                                            
                                            if (!is_wp_error($id)) {
                                                /**
                                                 * Generate attachment metadata and upload to CDN
                                                 */
                                                require_once ABSPATH . 'wp-admin/includes/image.php';
                                                wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $dst));
                                                
                                                $attachments[$src] = array(
                                                    $dst, 
                                                    $dst_url
                                                );
                                                
                                                $result = true;
                                            } else {
                                                $error = 'Unable to insert attachment';
                                            }
                                        }
                                    }
                                    
                                    /**
                                     * If attachment was successfully created then replace links
                                     */
                                    if ($result) {
                                        $replace = sprintf('%s="%s"', $attribute, $dst_url);
                                        
                                        // replace $search with $replace
                                        $post_content = str_replace($search, $replace, $post_content);
                                        
                                        $replaced[$search] = $replace;
                                        $error = 'OK';
                                    }
                                } else {
                                    $error = 'File type rejected';
                                }
                            } else {
                                $error = 'File already exists in the media library';
                            }
                            
                            /**
                             * Add new entry to the log file
                             */
                            $results[] = array(
                                'src' => $src, 
                                'dst' => $dst, 
                                'result' => $result, 
                                'error' => $error
                            );
                        }
                    }
                    
                    /**
                     * If post content was chenged then update DB
                     */
                    if ($post_content != $post->post_content) {
                        wp_update_post(array(
                            'ID' => $post->ID, 
                            'post_content' => $post_content
                        ));
                    }
                }
            }
        }
    }
    
    /**
     * Rename domain
     *
     * @param array $names
     * @param integer $limit
     * @param integer $offset
     * @param integer $count
     * @param integer $total
     * @param integer $results
     * @return void
     */
    function rename_domain($names, $limit, $offset, &$count, &$total, &$results)
    {
        global $wpdb;
        
        @set_time_limit(300);
        
        $count = 0;
        $total = 0;
        $results = array();
        
        $upload_info = w3_upload_info();
        
        foreach ($names as $index => $name) {
            $names[$index] = str_ireplace('www.', '', $name);
        }
        
        if ($upload_info) {
            $sql = sprintf('SELECT
        		ID,
        		post_content,
        		post_date
            FROM
                %sposts
            WHERE
                post_status = "publish"
                AND (post_type = "post" OR post_type = "page")
                AND (post_content LIKE "%%src=%%"
                	OR post_content LIKE "%%href=%%")
       		', $wpdb->prefix);
            
            if ($limit) {
                $sql .= sprintf(' LIMIT %d', $limit);
                
                if ($offset) {
                    $sql .= sprintf(' OFFSET %d', $offset);
                }
            }
            
            $posts = $wpdb->get_results($sql);
            
            if ($posts) {
                $count = count($posts);
                $total = $this->get_rename_posts_count();
                $names_quoted = array_map('w3_preg_quote', $names);
                
                foreach ($posts as $post) {
                    $matches = null;
                    $post_content = $post->post_content;
                    $regexp = '~(href|src)=[\'"]?(https?://(www\.)?(' . implode('|', $names_quoted) . ')' . w3_preg_quote($upload_info['baseurlpath']) . '([^\'"<>\s]+))[\'"]~';
                    
                    if (preg_match_all($regexp, $post_content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $old_url = $match[2];
                            $new_url = sprintf('%s/%s', $upload_info['baseurl'], $match[5]);
                            $post_content = str_replace($old_url, $new_url, $post_content);
                            
                            $results[] = array(
                                'old' => $old_url, 
                                'new' => $new_url, 
                                'result' => true, 
                                'error' => 'OK'
                            );
                        }
                    }
                    
                    if ($post_content != $post->post_content) {
                        wp_update_post(array(
                            'ID' => $post->ID, 
                            'post_content' => $post_content
                        ));
                    }
                }
            }
        }
    }
    
    /**
     * Returns attachments count
     *
     * @return integer
     */
    function get_attachments_count()
    {
        global $wpdb;
        
        $sql = sprintf('SELECT
        		COUNT(DISTINCT p.ID)
            FROM
                %sposts AS p
            JOIN
                %spostmeta AS pm ON p.ID = pm.post_ID AND (pm.meta_key = "_wp_attached_file" OR pm.meta_key = "_wp_attachment_metadata")
            WHERE
                p.post_type = "attachment"', $wpdb->prefix, $wpdb->prefix);
        
        return $wpdb->get_var($sql);
    }
    
    /**
     * Returns import posts count
     *
     * @return integer
     */
    function get_import_posts_count()
    {
        global $wpdb;
        
        $sql = sprintf('SELECT
        		COUNT(*)
            FROM
                %sposts
            WHERE
                post_status = "publish"
                AND (post_type = "post" OR post_type = "page")
                AND (post_content LIKE "%%src=%%"
                	OR post_content LIKE "%%href=%%")
                ', $wpdb->prefix);
        
        return $wpdb->get_var($sql);
    }
    
    /**
     * Returns rename posts count
     *
     * @return integer
     */
    function get_rename_posts_count()
    {
        return $this->get_import_posts_count();
    }
    
    /**
     * Returns array of files to upload
     * 
     * @return array
     */
    function get_files()
    {
        $files = array();
        
        if ($this->_config->get_boolean('cdn.includes.enable')) {
            $files = array_merge($files, $this->get_files_includes());
        }
        
        if ($this->_config->get_boolean('cdn.theme.enable')) {
            $files = array_merge($files, $this->get_files_theme());
        }
        
        if ($this->_config->get_boolean('cdn.minify.enable')) {
            $files = array_merge($files, $this->get_files_minify());
        }
        
        if ($this->_config->get_boolean('cdn.custom.enable')) {
            $files = array_merge($files, $this->get_files_custom());
        }
        
        return $files;
    }
    
    /**
     * Exports includes to CDN
     */
    function get_files_includes()
    {
        $files = $this->search_files(ABSPATH . WPINC, WPINC, $this->_config->get_string('cdn.includes.files'));
        
        return $files;
    }
    
    /**
     * Exports theme to CDN
     */
    function get_files_theme()
    {
        /**
         * If mobile support enabled
         * we should upload whole themes directory
         */
        if ($this->_config->get_boolean('mobile.enabled')) {
            $theme_root = get_theme_root();
        } else {
            $theme_root = get_stylesheet_directory();
        }
        
        $theme_dir = ltrim(str_replace(ABSPATH, '', $theme_root), '/\\');
        $files = $this->search_files($theme_root, $theme_dir, $this->_config->get_string('cdn.theme.files'));
        
        return $files;
    }
    
    /**
     * Exports min files to CDN
     */
    function get_files_minify()
    {
        $files = array();
        
        if (W3TC_PHP5 && $this->_config->get_boolean('minify.rewrite')) {
            require_once W3TC_LIB_W3_DIR . '/Plugin/Minify.php';
            $minify = & W3_Plugin_Minify::instance();
            $urls = $minify->get_urls();
            
            if ($this->_config->get_string('minify.engine') == 'file') {
                foreach ($urls as $url) {
                    w3_http_get($url);
                }
                
                $files = $this->search_files(W3TC_CACHE_FILE_MINIFY_DIR, W3TC_CONTENT_MINIFY_DIR_NAME, '*.css;*.js');
            } else {
                foreach ($urls as $url) {
                    $file = w3_normalize_file($url);
                    $file = w3_translate_file($file);
                    
                    if (!w3_is_url($file)) {
                        $file = ltrim(str_replace(W3TC_CONTENT_MINIFY_DIR_NAME, '', $file), '/');
                        
                        $dir = dirname($file);
                        
                        if ($dir) {
                            w3_mkdir($dir, 0755, W3TC_CACHE_FILE_MINIFY_DIR);
                        }
                        
                        if (w3_download($url, W3TC_CACHE_FILE_MINIFY_DIR . '/' . $file) !== false) {
                            $files[] = W3TC_CONTENT_MINIFY_DIR_NAME . '/' . $file;
                        }
                    }
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Exports custom files to CDN
     */
    function get_files_custom()
    {
        $files = array();
        $custom_files = $this->_config->get_array('cdn.custom.files');
        
        foreach ($custom_files as $custom_file) {
            if ($custom_file != '') {
                $custom_file = w3_normalize_file($custom_file);
                $dir = trim(dirname($custom_file), '/\\');
                
                if ($dir == '.') {
                    $dir = '';
                }
                
                $mask = basename($custom_file);
                $files = array_merge($files, $this->search_files(ABSPATH . $dir, $dir, $mask));
            }
        }
        
        return $files;
    }
    
    /**
     * Link replace callback
     *
     * @param array $matches
     * @return string
     */
    function link_replace_callback($matches)
    {
        global $wpdb;
        static $queue = null, $reject_files = null;
        
        list($match, $quote, $url, $domain_url, $www, $path) = $matches;
        
        $path = ltrim($path, '/');
        
        /**
         * Check if URL was already replaced
         */
        if (isset($this->replaced_urls[$url])) {
            return $quote . $this->replaced_urls[$url];
        }
        
        /**
         * Check URL for rejected files
         */
        if ($reject_files === null) {
            $reject_files = $this->_config->get_array('cdn.reject.files');
        }
        
        foreach ($reject_files as $reject_file) {
            if ($reject_file != '') {
                $reject_file = w3_normalize_file($reject_file);
                $reject_file_regexp = '~^(' . $this->get_regexp_by_mask($reject_file) . ')$~i';
                
                if (preg_match($reject_file_regexp, $path)) {
                    return $match;
                }
            }
        }
        
        /**
         * Don't replace URL for files that are in the CDN queue
         */
        if ($queue === null) {
            $sql = sprintf('SELECT remote_path FROM %s', $wpdb->prefix . W3TC_CDN_TABLE_QUEUE);
            $queue = $wpdb->get_col($sql);
        }
        
        if (in_array($path, $queue)) {
            return $match;
        }
        
        /**
         * Do replacement
         */
        $cdn = & $this->get_cdn();
        
        $new_url = $cdn->format_url($path);
        
        if ($new_url) {
            $this->replaced_urls[$url] = $new_url;
            
            return $quote . $new_url;
        }
        
        return $match;
    }
    
    /**
     * Search files
     *
     * @param string $search_dir
     * @param string $mask
     * @param boolean $recursive
     * @return array
     */
    function search_files($search_dir, $base_dir, $mask = '*.*', $recursive = true)
    {
        static $stack = array();
        $files = array();
        $ignore = array(
            '.svn', 
            '.git', 
            '.DS_Store', 
            'CVS', 
            'Thumbs.db', 
            'desktop.ini'
        );
        
        $dir = @opendir($search_dir);
        
        if ($dir) {
            while (($entry = @readdir($dir)) !== false) {
                if ($entry != '.' && $entry != '..' && !in_array($entry, $ignore)) {
                    $path = $search_dir . '/' . $entry;
                    
                    if (@is_dir($path) && $recursive) {
                        array_push($stack, $entry);
                        $files = array_merge($files, $this->search_files($path, $base_dir, $mask, $recursive));
                        array_pop($stack);
                    } else {
                        $regexp = '~^(' . $this->get_regexp_by_mask($mask) . ')$~i';
                        
                        if (preg_match($regexp, $entry)) {
                            $files[] = ($base_dir != '' ? $base_dir . '/' : '') . (($p = implode('/', $stack)) != '' ? $p . '/' : '') . $entry;
                        }
                    }
                }
            }
            
            @closedir($dir);
        }
        
        return $files;
    }
    
    /**
     * Returns regexp by mask
     *
     * @param string $mask
     * @return string
     */
    function get_regexp_by_mask($mask)
    {
        $mask = trim($mask);
        $mask = w3_preg_quote($mask);
        
        $mask = str_replace(array(
            '\*', 
            '\?', 
            ';'
        ), array(
            '@ASTERISK@', 
            '@QUESTION@', 
            '|'
        ), $mask);
        
        $regexp = str_replace(array(
            '@ASTERISK@', 
            '@QUESTION@'
        ), array(
            '[^\\?\\*:\\|"<>]*', 
            '[^\\?\\*:\\|"<>]'
        ), $mask);
        
        return $regexp;
    }
    
    /**
     * Normalizes attachment file
     *
     * @param string $file
     * @return string
     */
    function normalize_attachment_file($file)
    {
        $upload_info = w3_upload_info();
        if ($upload_info) {
            $file = ltrim(str_replace($upload_info['basedir'], '', $file), '/\\');
            $matches = null;
            
            if (preg_match('~(\d{4}/\d{2}/)?[^/]+$~', $file, $matches)) {
                $file = $matches[0];
            }
        }
        
        return $file;
    }
    
    /**
     * Returns CDN object
     *
     * @return W3_Cdn_Base
     */
    function &get_cdn()
    {
        static $cdn = array();
        
        if (!isset($cdn[0])) {
            $engine = $this->_config->get_string('cdn.engine');
            $engine_config = array();
            
            switch ($engine) {
                case 'mirror':
                    $engine_config = array(
                        'domain' => $this->_config->get_array('cdn.mirror.domain')
                    );
                    break;
                
                case 'netdna':
                    $engine_config = array(
                        'apiid' => $this->_config->get_string('cdn.netdna.apiid'), 
                        'apikey' => $this->_config->get_string('cdn.netdna.apikey'), 
                        'domain' => $this->_config->get_array('cdn.netdna.domain')
                    );
                    break;
                
                case 'ftp':
                    $engine_config = array(
                        'host' => $this->_config->get_string('cdn.ftp.host'), 
                        'user' => $this->_config->get_string('cdn.ftp.user'), 
                        'pass' => $this->_config->get_string('cdn.ftp.pass'), 
                        'path' => $this->_config->get_string('cdn.ftp.path'), 
                        'pasv' => $this->_config->get_boolean('cdn.ftp.pasv'), 
                        'domain' => $this->_config->get_array('cdn.ftp.domain')
                    );
                    break;
                
                case 's3':
                    $engine_config = array(
                        'key' => $this->_config->get_string('cdn.s3.key'), 
                        'secret' => $this->_config->get_string('cdn.s3.secret'), 
                        'bucket' => $this->_config->get_string('cdn.s3.bucket'), 
                        'cname' => $this->_config->get_array('cdn.s3.cname'), 
                        'compression' => ($this->_config->get_boolean('browsercache.enabled') && $this->_config->get_boolean('browsercache.html.compression'))
                    );
                    break;
                
                case 'cf':
                    $engine_config = array(
                        'key' => $this->_config->get_string('cdn.cf.key'), 
                        'secret' => $this->_config->get_string('cdn.cf.secret'), 
                        'bucket' => $this->_config->get_string('cdn.cf.bucket'), 
                        'id' => $this->_config->get_string('cdn.cf.id'), 
                        'cname' => $this->_config->get_array('cdn.cf.cname'), 
                        'compression' => ($this->_config->get_boolean('browsercache.enabled') && $this->_config->get_boolean('browsercache.html.compression'))
                    );
                    break;
                
                case 'rscf':
                    $engine_config = array(
                        'user' => $this->_config->get_string('cdn.rscf.user'), 
                        'key' => $this->_config->get_string('cdn.rscf.key'), 
                        'container' => $this->_config->get_string('cdn.rscf.container'), 
                        'id' => $this->_config->get_string('cdn.rscf.id'), 
                        'cname' => $this->_config->get_array('cdn.rscf.cname')
                    );
                    break;
            }
            
            require_once W3TC_LIB_W3_DIR . '/Cdn.php';
            $cdn[0] = & W3_Cdn::instance($engine, $engine_config);
            
            /**
             * Set cache config for CDN
             */
            if ($this->_config->get_boolean('browsercache.enabled')) {
                require_once W3TC_LIB_W3_DIR . '/Plugin/BrowserCache.php';
                $w3_plugin_browsercache = & W3_Plugin_BrowserCache::instance();
                
                $cdn[0]->cache_config = $w3_plugin_browsercache->get_cache_config();
            }
        }
        
        return $cdn[0];
    }
    
    /**
     * Returns debug info
     *
     * @return string
     */
    function get_debug_info()
    {
        $debug_info = "<!-- W3 Total Cache: CDN debug info:\r\n";
        $debug_info .= sprintf("%s%s\r\n", str_pad('Engine: ', 20), $this->_config->get_string('cdn.engine'));
        
        if ($this->cdn_reject_reason) {
            $debug_info .= sprintf("%s%s\r\n", str_pad('Reject reason: ', 20), $this->cdn_reject_reason);
        }
        
        if (count($this->replaced_urls)) {
            $debug_info .= "Replaced URLs:\r\n";
            
            foreach ($this->replaced_urls as $old_url => $new_url) {
                $debug_info .= sprintf("%s => %s\r\n", $old_url, $new_url);
            }
        }
        
        $debug_info .= '-->';
        
        return $debug_info;
    }
    
    /**
     * Check if we can do CDN logic
     * @return boolean
     */
    function can_cdn()
    {
        /**
         * Skip if CDN is disabled
         */
        if (!$this->_config->get_boolean('cdn.enabled')) {
            $this->cdn_reject_reason = 'CDN is disabled';
            
            return false;
        }
        
        /**
         * Skip if admin
         */
        if (defined('WP_ADMIN')) {
            $this->cdn_reject_reason = 'wp-admin';
            
            return false;
        }
        
        /**
         * Check for WPMU's and WP's 3.0 short init
         */
        if (defined('SHORTINIT') && SHORTINIT) {
            $this->cdn_reject_reason = 'Short init';
            
            return false;
        }
        
        /**
         * Check User agent
         */
        if (!$this->check_ua()) {
            $this->cdn_reject_reason = 'user agent is rejected';
            
            return false;
        }
        
        /**
         * Check request URI
         */
        if (!$this->check_request_uri()) {
            $this->cdn_reject_reason = 'request URI is rejected';
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Returns true if we can do CDN logic
     * 
     * @return string
     * @return boolean
     */
    function can_cdn2(&$buffer)
    {
        /**
         * Check for database error
         */
        if (w3_is_database_error($buffer)) {
            $this->cdn_reject_reason = 'Database Error occurred';
            
            return false;
        }
        
        /**
         * Check for DONOTCDN constant
         */
        if (defined('DONOTCDN') && DONOTCDN) {
            $this->cdn_reject_reason = 'DONOTCDN constant is defined';
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Checks User Agent
     *
     * @return boolean
     */
    function check_ua()
    {
        foreach ($this->_config->get_array('cdn.reject.ua') as $ua) {
            if (isset($_SERVER['HTTP_USER_AGENT']) && stristr($_SERVER['HTTP_USER_AGENT'], $ua) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Checks request URI
     *
     * @return boolean
     */
    function check_request_uri()
    {
        $auto_reject_uri = array(
            'wp-login', 
            'wp-register'
        );
        
        foreach ($auto_reject_uri as $uri) {
            if (strstr($_SERVER['REQUEST_URI'], $uri) !== false) {
                return false;
            }
        }
        
        foreach ($this->_config->get_array('cdn.reject.uri') as $expr) {
            $expr = trim($expr);
            if ($expr != '' && preg_match('~' . $expr . '~i', $_SERVER['REQUEST_URI'])) {
                return false;
            }
        }
        
        return true;
    }
}
