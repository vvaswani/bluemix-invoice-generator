<?php
namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\MissingOptionsException;

/**
 * @Annotation
 */
class Lines extends Constraint
{
    public $incompleteMessage = 'Invoice line %string% is incomplete.';
    public $missingMessage = 'Invoice data is missing.';
    public $invalidQuantityMessage = 'Invoice line %string% specifies a non-numeric quantity.';
    public $invalidRateMessage = 'Invoice line %string% specifies a non-numeric rate.';
}
