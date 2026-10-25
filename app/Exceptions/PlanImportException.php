<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An Excel plan that cannot be imported: an unrecognised formula or layout, or computed day
 * numbers that disagree with the workbook's own. Nothing has been written.
 */
class PlanImportException extends RuntimeException {}
