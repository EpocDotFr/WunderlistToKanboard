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

require_once('idiorm.php'); // Include the ORM to play with the kanboard database

function message($message) {
  echo $message.PHP_EOL;
}

// This function is copied from kanboard, it generate a unique identifier for each projets (public access)
function generateToken() {
  if (ini_get('open_basedir') === '' and strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
    $token = file_get_contents('/dev/urandom', false, null, 0, 30);
  } else {
    $token = uniqid(mt_rand(), true);
  }

  return hash('crc32b', $token);
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
  message('! Error (errnum '.json_last_error().') reading the JSON data from the Wunderlist export file "'.WUNDERLIST_FILE.'"');
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

  // We insert an "Inbox" list in the imported data, the script will create it as an Inbox project in kanboard to support Wunderlist tasks in the Inbox list
  $inbox_list = new stdClass();
  $inbox_list->id = 'inbox';
  $inbox_list->title = INBOX_TITLE;

  $wunderlist_json_data->lists[] = $inbox_list;

  // Importing lists as projects
  foreach ($wunderlist_json_data->lists as $list) {
    $project = ORM::for_table('projects')->create();

    $project->name = $list->title;
    $project->is_active = 1;
    $project->token = generateToken();

    $project->save();

    message('> Projects > '.$project->name);

    // Save the Wunderlist and kanboard project IDs for future use
    $projects[$list->id] = array(
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

      $projects[$list->id]['columns'][] = $column->id; // Save this column ID for future use
    }
  }

  // -------------------------------------------------------------------------- //

  message('> Main tasks');

  // Importing the main tasks (without their sub-tasks at this moment)
  foreach ($wunderlist_json_data->tasks as $task_to_import) {
    if (isset($task_to_import->parent_id)) { // If it's a sub-task, we ignore it (they will be handled later)
      continue;
    }

    $task_imported = ORM::for_table('tasks')->create();

    $task_imported->title = $task_to_import->title;
    $task_imported->description = isset($task_to_import->note) ? str_replace('\n', PHP_EOL, $task_to_import->note) : null;
    $task_imported->date_creation = date_create($task_to_import->created_at)->getTimestamp();
    $task_imported->color_id = $task_to_import->starred ? 'red' : 'yellow'; // If the task was starred on Wunderlist, we assign the red color. Yellow by default (same as kanboard)
    $task_imported->project_id = $projects[$task_to_import->list_id]['id'];
    $task_imported->column_id = isset($task_to_import->completed_at) ? $projects[$task_to_import->list_id]['columns'][KANBOARD_COMPLETED_COLUMN] : $projects[$task_to_import->list_id]['columns'][KANBOARD_DEFAULT_COLUMN]; // Move the task in the right column if it is completed or not
    $task_imported->owner_id = 0; // The tasks are never assigned
    $task_imported->is_active = isset($task_to_import->completed_at) ? 0 : 1;
    $task_imported->date_completed = isset($task_to_import->completed_at) ? date_create($task_to_import->completed_at)->getTimestamp() : null;
    $task_imported->score = null;
    //$task_imported->date_due = isset($task_to_import->due_date) ? date_create($task_to_import->due_date)->getTimestamp() : null;
    
    $task_imported->save();

    $tasks[$task_to_import->id] = $task_imported->id;

    message('> Main tasks > '.$task_imported->title);
  }

  message('> Sub tasks');

  // Sub-tasks are merged in their main task's description like this :
  //   - [ ] Sub-task 1
  //   - [ ] Sub-task 2
  foreach ($wunderlist_json_data->tasks as $task_to_import) {
    if (!isset($task_to_import->parent_id)) { // If it's not a sub-task, we ignore it
      continue;
    }

    if (!isset($tasks[$task_to_import->parent_id])) { // The parent task does not exists, for a reason or another
      continue;
    }

    $main_task = ORM::for_table('tasks')->find_one($tasks[$task_to_import->parent_id]); // Get the main task of this sub-task

    if (!$main_task) {
      continue;
    }

    $main_task->description = $main_task->description.PHP_EOL.'  - ['.(isset($task_to_import->completed_at) ? 'X' : ' ').'] '.$task_to_import->title;

    $main_task->save();

    message('> Sub tasks > '.$task_to_import->title);
  }

  message('Saving database...');

  ORM::get_db()->commit();

  message('Finished importing !');
} catch (Exception $e) {
  message('! WE HAVE A SITUATION HERE, ROLLBACK');

  ORM::get_db()->rollBack();

  message('! Oops, an exception was raised : '.$e->getMessage().' on line '.$e->getLine());
}
