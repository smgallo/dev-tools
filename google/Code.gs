/**
 * Color a simple gantt-style chart based on task definition, duration, percent complete, and
 * task background color. To color the chart, select the entire chart area (not including the tasks
 * or headers) and then select "Color Timeline" from the "Timeline" menu item.
 *
 * Features:
 * - Define tasks, subtasks, parent tasks, and milestones along with duration and percent complete
 * - Milestones have no duration
 * - Parent tasks are shown to enclose subtasks
 * - Task bars are drawn using the background color of the task definition
 * - The start and end weeks are calculated sequentially based on the end of the previous task and
 *   the task duration. To start a task on a specific week, simply overwrite the cell fomula
 *
 * The default start week formula is: =IF(0=$C6,$F5,$F5+1)
 * The default end week formula is: =IF(0=$C6, $E6, $E6+$C6-1)
 */

/* ----------------------------------------------------------------------------------------------------
 * Create a custom menu item to enable coloring the timeline. We do not have permission to set cell
 * background values in a custom funtion but we do when initiated via a UI element.
 * ----------------------------------------------------------------------------------------------------
 */

function onOpen()
{
  var menu = SpreadsheetApp.getUi().createMenu('Timeline').addItem('Color Timeline', 'setCellColor').addToUi();
}

/* ----------------------------------------------------------------------------------------------------
 * Set the color of a cell based on the duration and the week under which it falls. We make the following
 * assumptions:
 * 1. The range of cells to be updated has been selected in the spreadsheet (active range).
 * 2. The row immediately above the active range is a list of week numbers indicating the week under
 *    which a cell falls.
 * 3. The two columns immediately preceeding the active range contain the start and end days of the
 *    task relative to the start and end of the project.
 * 4. Milestones are tasks with 0 duration (start day = end day)
 * ----------------------------------------------------------------------------------------------------
 */

function setCellColor()
{
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  // The active range is the area to be updated based on the task durations. Cells in the range are 1-based.
  var activeRange = sheet.getActiveRange();
  var startRow = activeRange.getRow();
  var endRow = startRow + activeRange.getHeight() - 1;
  var startCol = activeRange.getColumn();
  var endCol = startCol + activeRange.getWidth() - 1;

  // The week numbers are listed one row above the active range. getValues() returns a 0-based 2-d array.
  // var weekNumbers = sheet.getRange(startRow - 1, startCol, 1, activeRange.getWidth()).getValues()[0];

  // The start and end weeks for each task are the 3 columns before the active range.
  var startAndEndWeeks = sheet.getRange(startRow, startCol - 2, activeRange.getHeight(), 2).getValues();
  var startAndEndWeeks = sheet.getRange(startRow, startCol - 3, activeRange.getHeight(), 2).getValues();

  // The duration is 4 columns before the active range
  var duration = sheet.getRange(startRow, startCol - 4, activeRange.getHeight(), 1).getValues();

  // The task types are "m" for milestone, "-" for parent class, and "s" for subclass
  var taskTypes = sheet.getRange(startRow, startCol - 5, activeRange.getHeight(), 1).getValues();
  
  // The percent complete is 0 - 100 and is 1 columns before the active range
  var percentComplete = sheet.getRange(startRow, startCol - 3, activeRange.getHeight(), 1).getValues();
  var percentComplete = sheet.getRange(startRow, startCol - 1, activeRange.getHeight(), 1).getValues();

  for ( var currentRow = startRow, rowIndex = 0; currentRow <= endRow; currentRow++, rowIndex++ ) {
    var startWeek = startAndEndWeeks[rowIndex][0]
    var endWeek = startAndEndWeeks[rowIndex][1];

    if ( "" == startWeek || isNaN(Number(startWeek)) || "" == endWeek || isNaN(Number(endWeek)) ) {
      throw new Error("Inavlid start ('" + startWeek + "') or end ('" + endWeek + "') week: must be an integer");
    }

    var taskStartCol = (startCol + startWeek - 1)
    var taskNumCol = (endWeek - startWeek + 1);

    // Clear any existing timeline for this row

    sheet.getRange(currentRow, startCol, 1, activeRange.getWidth()).clearContent().setFontWeight("normal").setBackground("#ffffff");

    // Determine the range based on the start and end weeks (round down). The cells are offset by the active region.
    var taskRange = sheet.getRange(currentRow, taskStartCol, 1, taskNumCol);
    var numCellsMarkedComplete = ( percentComplete[rowIndex] > 0 ? Math.floor(percentComplete[rowIndex]/100 * taskNumCol) : 0 );

    // Use the task type column to determine what to display

    if ( 'M' == taskTypes[rowIndex].toString().toUpperCase() ) {
      // Milestone
      taskRange.setValue("X").setFontWeight("bold");
    } else if ( '-' == taskTypes[rowIndex] ) {
      // Parent tasks simply show the span of all subtasks under them
      printParentTaskBar(sheet, rowIndex, currentRow, endRow, startCol, endCol, startAndEndWeeks, taskTypes);
    } else {
      var firstCellColor = sheet.getRange(currentRow, 1).getBackground();
      taskRange.setBackground(firstCellColor);
      if ( numCellsMarkedComplete > 0 ) {
        // Mark the percentage of the completed cells
        sheet.getRange(currentRow, taskStartCol, 1, numCellsMarkedComplete).setBackground("#555555");
      }
    }
  }

}  // setCellColor()

/* ----------------------------------------------------------------------------------------------------
 * Display the task bar for the parent tasks. This will be the length of the sum of all subtasks.
 * ----------------------------------------------------------------------------------------------------
 */

function printParentTaskBar(sheet, rowIndex, currentRow, endRow, startCol, endCol, startAndEndWeeks, taskTypes)
{
  var subTaskStartCol = endCol, subTaskNumCol = 0;
  for ( subtaskIndex = rowIndex + 1; subtaskIndex <= endRow; subtaskIndex++ ) {
    // Be sure that we don't go off the end of our timeline
    if ( null == taskTypes[subtaskIndex] || 'S' != taskTypes[subtaskIndex].toString().toUpperCase() ) {
      break;
    }
    subTaskStartCol = Math.min(subTaskStartCol, startCol + startAndEndWeeks[subtaskIndex][0] - 1);
    subTaskNumCol = Math.max(subTaskNumCol, startAndEndWeeks[subtaskIndex][1]);
  }

  // Adjust the number of columns to account for tasks that don't start at week 1
  subTaskNumCol -= (subTaskStartCol - startCol);

  if ( 1 == subTaskNumCol ) {
    taskRange = sheet.getRange(currentRow, subTaskStartCol, 1, subTaskNumCol).setValue("'<>").setFontWeight("bold");
  } else if ( 2 == subTaskNumCol ) {
    taskRange = sheet.getRange(currentRow, subTaskStartCol, 1, 1).setValue("'<=").setFontWeight("bold");
    taskRange = sheet.getRange(currentRow, subTaskStartCol + 1, 1, 1).setValue("'=>").setFontWeight("bold");
  } else {
    taskRange = sheet.getRange(currentRow, subTaskStartCol, 1, 1).setValue("'<=").setFontWeight("bold");
    taskRange = sheet.getRange(currentRow, subTaskStartCol + 1, 1, subTaskNumCol - 2).setValue("'==").setFontWeight("bold");
    taskRange = sheet.getRange(currentRow, subTaskStartCol + subTaskNumCol - 1, 1, 1).setValue("'=>").setFontWeight("bold");
  }
}

