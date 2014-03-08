WunderlistToKanboard
====================

Quick and dirty PHP script to import Wunderlist (http://www.wunderlist.com/) tasks and lists in a kanboard (http://kanboard.net/) database

## Prerequisites

  - PHP (any 5.x version should work, this script was tested on PHP 5.3.27)
  - A command line interpreter
  - Kanboard 1.0.2 **/!\ this script was tested on this version only**
  - A beer

## Quick usage

  1. Get the script here : https://github.com/EpocDotFr/WunderlistToKanboard/archive/master.zip and unzip the content somewhere on your computer
  2. Go to the Wunderlist settings > **Account** tab, click on the **Create backup** button, and download the backup file
  3. Rename the downloaded file to `wunderlist.json`
  4. Download your original Kanboard database (via FTP or via the **Settings** tab > **Download the database** link. Remember : Kanboard 1.0.2  only). Don't forget to make a backup
  5. Throw these two files in the script directory. Your should have at this time `wunderlist.json` and `db.sqlite` next to the `run.php` file
  6. In your command line interpreter, launch `php run.php`. You should see some logging message
  7. IF all is good, replace the old Kanboard database with the new one (e.g via FTP)
  8. Your lists and tasks has now been imported. You can drink your beer :)

## How it works

You can open the `run.php` file if you want to know more, it is quite commented (I think). You can also define some settings like changing the generated colum names to match your language.

Kanboard and Wunderlist are very different, so there's some things to know about what happens to your tasks and lists in certain cases :

  - Lists are imported as Projects
  - Users are not imported
  - Starred tasks will have a color of red, otherwise yellow (Kanboard default)
  - Sub-tasks are merged in their main task's description

All the other data supported by Kanboard is imported with no problems.

## End words

If you have questions or problems, you can [submit an issue](https://github.com/EpocDotFr/WunderlistToKanboard/issues).