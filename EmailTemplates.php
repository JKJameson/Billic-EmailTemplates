<?php
class EmailTemplates {
	public $settings = array(
		'name' => 'Email Templates',
		'admin_menu_category' => 'Settings',
		'admin_menu_name' => 'Email Templates',
		'admin_menu_icon' => '<i class="icon-email-envelope"></i>',
		'description' => 'Configure the emails that Billic sends.',
	);
	/*
	send(array(
		'to' => 'someone@example.org',
		'template_id' => 123, // ID of template
		'vars' => array(
			'services' => $service,
			'users' => $user,
		),
	));
	*/
	function send($array) {
		global $billic, $db;
		$template = $db->q('SELECT * FROM `emailtemplates` WHERE `id` = ?', $array['template_id']);
		$template = $template[0];
		if (empty($template)) {
			return 'Email template no longer exists';
		}
		$subject = $this->rewrite($template['subject'], $array['vars']);
		$message = $this->rewrite($template['message'], $array['vars']);
		$billic->email($array['to'], $subject, $message);
		return true;
	}
	function rewrite($text, $vars) {
		global $billic, $db;
		ob_start();
		$text = eval('?>' . $text);
		$text = ob_get_contents();
		ob_end_clean();
		$vars['billic']['url'] = 'http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/';
		foreach ($vars as $replace_me => $replace_with) {
			if (is_array($replace_with)) {
				foreach ($replace_with as $replace_me2 => $replace_with2) {
					$text = str_replace('{$' . $replace_me . '/' . $replace_me2 . '}', $replace_with2, $text);
				}
			} else {
				$text = str_replace('{$' . $replace_me . '}', $replace_with, $text);
			}
		}
		return $text;
	}
	function preview($text, $vars) {
		global $billic, $db;
		$new_vars = array();
		foreach ($vars as $k => $var) {
			if (substr($var, 0, 4) == 'sql/') {
				$table = substr($var, 4);
				if (!ctype_alnum($table)) {
					echo 'Unable to parse table "' . safe($table) . '" because it is not alphanumeric<br>';
					continue;
				}
				$row = $db->q('SELECT * FROM `' . $table . '` LIMIT 1');
				$new_vars[$table] = $row[0];
			} else {
				$new_vars[$var] = uniqid();
			}
		}
		return $this->rewrite($text, $new_vars);
	}
	function checkPHP($code) {
		global $billic, $db;
		ob_start();
		$eval = @eval('?>' . $code);
		ob_get_clean();
		if ($eval === FALSE) {
			return false;
		} else {
			return true;
		}
	}
	function admin_area() {
		global $billic, $db;
		if (isset($_GET['Edit'])) {
			if (isset($_POST['update'])) {
				if (!$this->checkPHP($_POST['message'])) {
					$billic->errors[] = 'There is a PHP Error with the content.';
				}
				if (empty($billic->errors)) {
					if (!empty($_POST['default'])) {
						$db->q('UPDATE `emailtemplates` SET `default` = ? WHERE `default` = ?', '', $_POST['default']);
					}
					$db->q('UPDATE `emailtemplates` SET `subject` = ?, `message` = ?, `default` = ? WHERE `id` = ?', $_POST['subject'], $_POST['message'], $_POST['default'], $_GET['Edit']);
					$billic->status = 'updated';
				}
			}
			$template = $db->q('SELECT * FROM `emailtemplates` WHERE `id` = ?', $_GET['Edit']);
			$template = $template[0];
			if (empty($template)) {
				err('Template does not exist');
			}
			if (!empty($billic->errors) && !empty($_POST)) {
				$template['subject'] = $_POST['subject'];
				$template['message'] = $_POST['message'];
			}
			$billic->set_title('Admin/Template ' . safe($template['subject']));
			echo '<h1>Email Template: ' . safe($template['subject']) . '</h1>';
			$billic->show_errors();
			echo '<form method="POST"><table class="csstable-nohover"><tr><th colspan="2">Page Settings</th></td></tr>';
			echo '<tr><td width="125">Subject</td><td><input type="text" class="form-control" name="subject" value="' . safe($template['subject']) . '"></td></tr>';
			echo '<tr><td>Default Email for</td><td><select class="form-control" name="default"><option value="">None</option>';
			$defaults = $db->q('SELECT * FROM `emailtemplatesdefaults` ORDER BY `name` ASC');
			foreach ($defaults as $default) {
				echo '<option value="' . $default['name'] . '"' . ($default['name'] == $template['default'] ? ' selected' : '') . '>' . $default['name'] . '</option>';
			}
			echo '</select></td></tr>';
			echo '<tr><th colspan="2">Message</th></td></tr>';
			echo '<tr><td colspan="2"><i class="icon-check-mark"></i> PHP Code is supported<br><br><textarea id="pagecontent" name="message" style="width: 100%; height:500px">' . safe($template['message']) . '</textarea>';
			echo '
<link rel="stylesheet" href="/Modules/Core/codemirror/codemirror.css">
<script src="/Modules/Core/codemirror/codemirror.js"></script>
<script src="/Modules/Core/codemirror/matchbrackets.js"></script>
<script src="/Modules/Core/codemirror/htmlmixed.js"></script>
<script src="/Modules/Core/codemirror/xml.js"></script>
<script src="/Modules/Core/codemirror/javascript.js"></script>
<script src="/Modules/Core/codemirror/css.js"></script>
<script src="/Modules/Core/codemirror/clike.js"></script>
<script src="/Modules/Core/codemirror/php.js"></script>
			
			<script>var editor = CodeMirror.fromTextArea(document.getElementById("pagecontent"), {
      lineNumbers: true,
	  lineWrapping: true,
      mode: "application/x-httpd-php",
      matchBrackets: true,
	  indentUnit: 4,
      indentWithTabs: true
    });</script>';
			echo '</td></tr>';
			echo '<tr><th colspan="2">Variables</th></td></tr><tr><td colspan="2">';
			$type = $db->q('SELECT * FROM `emailtemplatestypes` WHERE `name` = ?', $template['type']);
			$type = $type[0];
			if (empty($type)) {
				err('Template type "' . safe($template['type']) . '" does not exist');
			}
			$vars = json_decode($type['vars'], true);
			echo safe('{$billic/url}') . '<br>';
			foreach ($vars as $var) {
				if (substr($var, 0, 4) == 'sql/') {
					$table = substr($var, 4);
					if (!ctype_alnum($table)) {
						echo 'Unable to parse table "' . safe($table) . '" because it is not alphanumeric<br>';
						continue;
					}
					$columns = $db->q('SHOW COLUMNS FROM `' . $table . '`');
					foreach ($columns as $column) {
						echo safe('From Database: {$' . $table . '/' . $column['Field'] . '}') . '<br>';
					}
				} else {
					echo '{$' . $var . '}<br>';
				}
			}
			if (!empty($template['subject']) || !empty($template['message'])) {
				echo '<tr><th colspan="2">Preview (Using Random Data)</th></tr><tr><td colspan="2"><b><u>';
				echo $this->preview($template['subject'], $vars);
				echo '</u></b><br>';
				echo $this->preview($template['message'], $vars);
				echo '</td></tr>';
			}
			echo '</td></tr><tr><td colspan="4" align="center"><input type="submit" class="btn btn-success" name="update" value="Update &raquo;"></td></tr></table></form>';
			return;
		}
		if (isset($_GET['New'])) {
			$title = 'New Template';
			$billic->set_title($title);
			echo '<h1>' . $title . '</h1>';
			$template_check = $db->q('SELECT * FROM `emailtemplates` WHERE `subject` = ?', $_POST['subject']);
			if (!empty($template_check)) {
				$billic->$billic->error('The subject is already in use. It must be unique.', 'subject');
			}
			$types = array();
			$types1 = $db->q('SELECT * FROM `emailtemplatestypes` ORDER BY `name` ASC');
			foreach ($types1 as $k => $v) {
				$types[$v['name']] = $v['name'];
			}
			unset($types1);
			$billic->module('FormBuilder');
			$form = array(
				'type' => array(
					'label' => 'Type',
					'type' => 'dropdown',
					'options' => $types,
					'required' => true,
					'default' => '',
				) ,
				'subject' => array(
					'label' => 'Subject',
					'type' => 'text',
					'required' => true,
					'default' => '',
				)
			);
			if (isset($_POST['Continue'])) {
				$billic->modules['FormBuilder']->check_everything(array(
					'form' => $form,
				));
				if (empty($billic->errors)) {
					$id = $db->insert('emailtemplates', array(
						'subject' => $_POST['subject'],
						'type' => $_POST['type'],
					));
					$billic->redirect('/Admin/EmailTemplates/Edit/' . $id . '/');
				}
			}
			$billic->show_errors();
			$billic->modules['FormBuilder']->output(array(
				'form' => $form,
				'button' => 'Continue',
			));
			return;
		}
		if (isset($_GET['Delete'])) {
			$db->q('DELETE FROM `emailtemplates` WHERE `id` = ?', urldecode($_GET['Delete']));
			$billic->status = 'deleted';
		}
		$billic->set_title('Email Templates');
		echo '<h1><i class="icon-email-envelope"></i> Email Templates</h1>';
		$billic->show_errors();
		echo '<a href="New/" class="btn btn-success"><i class="icon-plus"></i> New Template</a>';
		$templates = $db->q('SELECT `id`, `subject`, `default`, LENGTH(`message`) FROM `emailtemplates` ORDER BY `subject` ASC');
		echo '<table class="table table-striped"><tr><th>Subject</th><th>Default</th><th>Size</th><th>Actions</th></tr>';
		if (empty($templates)) {
			echo '<tr><td colspan="20">No Templates matching filter.</td></tr>';
		}
		foreach ($templates as $template) {
			echo '<tr><td>' . $template['subject'] . '</td><td>';
			if (empty($template['default'])) {
				echo 'None';
			} else {
				echo $template['default'];
			}
			echo '</td><td>' . $template['LENGTH(`message`)'] . '</td><td>';
			echo '<a href="/Admin/EmailTemplates/Edit/' . $template['id'] . '/" title="Edit" class="btn btn-primary btn-xs"><i class="icon-edit-write"></i> Edit</a>';
			echo '&nbsp;<a href="/Admin/EmailTemplates/Delete/' . $template['id'] . '/" class="btn btn-danger btn-xs" title="Delete" onClick="return confirm(\'Are you sure you want to delete?\');"><i class="icon-remove"></i> Delete</a>';
			echo '</td></tr>';
		}
		echo '</table>';
	}
}
