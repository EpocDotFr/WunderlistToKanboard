<?php
/**
 * WunderlistToKanboard
 *
 * Quick and dirty PHP script to import Wunderlist (http://www.wunderlist.com/) tasks and lists in a kanboard (http://kanboard.net/) database
 *
 * Documentation : https://github.com/EpocDotFr/WunderlistToKanboard
 */

// The Wunderlist file (JSON format)
define('WUNDERLIST_FILE', 'wunderlist.json');

// The kanboard SQLite database file
define('KANBOARD_FILE', 'db.sqlite');

// Default columns created for each projects in kanboard
// ** Edit the column titles only ! **
$KANBOARD_COLUMNS = array(
  array( // 0
    'title' => 'En attente',
    'position' => 1,
    'task_limit' => 0
  ),
  array( // 1
    'title' => 'Prêt',
    'position' => 2,
    'task_limit' => 0
  ),
  array( // 2
    'title' => 'En cours',
    'position' => 3,
    'task_limit' => 0
  ),
  array( // 3
    'title' => 'Terminé',
    'position' => 4,
    'task_limit' => 0
  )
);

// Default column number for the non-completed tasks
define('KANBOARD_DEFAULT_COLUMN', 0);

// Column number for the completed tasks
define('KANBOARD_COMPLETED_COLUMN', 3);

// Inbox list / project title
define('INBOX_TITLE', 'Boîte de réception');

// There's is nothing to modify after this line
// -------------------------------------------------------------------------- //

function message($message) {
  echo $message.PHP_EOL;
}

if (!function_exists('json_last_error_msg')) {
  function json_last_error_msg() {
    static $errors = array(
      JSON_ERROR_NONE             => null,
      JSON_ERROR_DEPTH            => 'Maximum stack depth exceeded',
      JSON_ERROR_STATE_MISMATCH   => 'Underflow or the modes mismatch',
      JSON_ERROR_CTRL_CHAR        => 'Unexpected control character found',
      JSON_ERROR_SYNTAX           => 'Syntax error, malformed JSON',
      JSON_ERROR_UTF8             => 'Malformed UTF-8 characters, possibly incorrectly encoded'
    );
    $error = json_last_error();
    return array_key_exists($error, $errors) ? $errors[$error] : "Unknown error ({$error})";
  }
}

if (!extension_loaded('sqlite3')) {
  message('! SQLite doesn\'t seems to be available.');
  exit();
}

require_once('idiorm.php'); // Include the ORM to play with the kanboard database

// This function is copied from kanboard, it generate a unique identifier for each projets (public access)
function generateToken() {
  if (function_exists('openssl_random_pseudo_bytes')) {
      return bin2hex(\openssl_random_pseudo_bytes(30));
  } else if (ini_get('open_basedir') === '' && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
      return hash('sha256', file_get_contents('/dev/urandom', false, null, 0, 30));
  }

  return hash('sha256', uniqid(mt_rand(), true));
}

// Open the kanboard's SQLite database
ORM::configure(array(
  'connection_string' => 'sqlite:./'.KANBOARD_FILE,
  'return_result_sets' => true,
  'error_mode' => PDO::ERRMODE_EXCEPTION,
  'driver_options' => array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES UTF8'),
  'id_column' => 'id'
));

message('Opening file '.WUNDERLIST_FILE);

// Getting the Wunderlist file content
$wunderlist_raw_data = file_get_contents(WUNDERLIST_FILE);

if ($wunderlist_raw_data === false) {
  message('! Error reading the Wunderlist export file "'.WUNDERLIST_FILE.'"');
  exit();
}

// Translating the Wunderlist file content to a PHP object
$wunderlist_json_data = json_decode($wunderlist_raw_data);

if ($wunderlist_json_data == null) {
  message('! Error reading the JSON data from the Wunderlist export file "'.WUNDERLIST_FILE.'" : '.json_last_error_msg());
  exit();
}

unset($wunderlist_raw_data); // Free some memory (in case of)

$projects = array(); // Array which will contain respectively the kanboard and Wunderlist IDs
$tasks = array(); // Same here

// -------------------------------------------------------------------------- //
// Let's start importing

ORM::get_db()->beginTransaction();

message('Started importing');

try {
  message('> Projects');

  // Importing lists as projects
  foreach ($wunderlist_json_data->data->lists as $list_to_import) {
    $project = ORM::for_table('projects')->create();

    $project->name = $list_to_import->list_type == 'inbox' ? INBOX_TITLE : $list_to_import->title; // Take the real inbox title
    $project->is_public = $list_to_import->public ? 1 : 0;
    $project->token = generateToken();
    $project->last_modified = date_create()->getTimestamp();

    $project->save();

    message('> Projects > '.$project->name);

    // Save the Wunderlist and kanboard project IDs for future use
    $projects[$list_to_import->id] = array(
      'id' => $project->id,
      'columns' => array()
    );

    // Generate the default kanboard columns for the project
    foreach ($KANBOARD_COLUMNS as $default_column) {
      $column = ORM::for_table('columns')->create();

      $column->title = $default_column['title'];
      $column->position = $default_column['position'];
      $column->project_id = $project->id;
      $column->task_limit = $default_column['task_limit'];
      
      $column->save();

      message('> Projects > '.$project->name.' > Columns > '.$column->title);

      $projects[$list_to_import->id]['columns'][] = $column->id; // Save this column ID for future use
    }
  }
  
  // -------------------------------------------------------------------------- //

  message('> Main tasks');

  // Importing the main tasks
  foreach ($wunderlist_json_data->data->tasks as $task_to_import) {
    $task_imported = ORM::for_table('tasks')->create();

    $task_imported->title = $task_to_import->title;
    $task_imported->date_creation = date_create($task_to_import->created_at)->getTimestamp();
    $task_imported->date_modification = date_create()->getTimestamp();
    $task_imported->color_id = $task_to_import->starred ? 'red' : 'yellow'; // If the task was starred on Wunderlist, we assign the red color. Yellow by default (same as kanboard)
    $task_imported->project_id = $projects[$task_to_import->list_id]['id'];
    $task_imported->column_id = $task_to_import->completed ? $projects[$task_to_import->list_id]['columns'][KANBOARD_COMPLETED_COLUMN] : $projects[$task_to_import->list_id]['columns'][KANBOARD_DEFAULT_COLUMN]; // Move the task in the right column if it is completed or not
    $task_imported->is_active = $task_to_import->completed ? 0 : 1;
    $task_imported->date_completed = $task_to_import->completed ? date_create($task_to_import->completed_at)->getTimestamp() : null;
    $task_imported->date_due = isset($task_to_import->due_date) ? date_create($task_to_import->due_date)->getTimestamp() : null;
    
    // Description (note)
    foreach ($wunderlist_json_data->data->notes as $note_to_import) {
      if ($note_to_import->task_id == $task_to_import->id) {
        $task_imported->description = str_replace('\n', PHP_EOL, $note_to_import->content);
        
        break;
      }
    }
    
    $task_imported->save();
    
    $tasks[$task_to_import->id] = $task_imported->id;

    message('> Main tasks > '.$task_imported->title);
  }

  message('> Sub tasks');

  // Sub-tasks time !
  foreach ($wunderlist_json_data->data->subtasks as $subtasks_to_import) {
    $sub_task_imported = ORM::for_table('task_has_subtasks')->create();
    
    $sub_task_imported->title = $subtasks_to_import->title;
    $sub_task_imported->status = $subtasks_to_import->completed ? 2 : 0;
    $sub_task_imported->task_id = $tasks[$subtasks_to_import->task_id];
    
    $sub_task_imported->save();

    message('> Sub tasks > '.$subtasks_to_import->title);
  }

  message('Saving database...');

  ORM::get_db()->commit();

  message('Finished importing !');
} catch (Exception $e) {
  message('! WE HAVE A SITUATION HERE, ROLLBACK');

  ORM::get_db()->rollBack();

  message('! Oops, an exception was raised : '.$e->getMessage().' on line '.$e->getLine());
}
