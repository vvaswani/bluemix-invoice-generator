<?php
namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class LinesValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        // check that at least one invoice line is complete
        $completeLines = 0;
        foreach ($value as $lineNum => $lineData) {
            if (!empty($lineData['item']) && !empty($lineData['qty']) && !empty($lineData['rate'])) {
                $completeLines++;
            }
        }        
        if ($completeLines == 0) {
            $this->context->buildViolation($constraint->missingMessage)
                ->addViolation();  
            return;
        }
    
        // assuming at least one line is complete
        // validate each line
        foreach ($value as $lineNum => $lineData) {   
            // skip empty lines
            if ( empty($lineData['item']) && empty($lineData['qty']) && empty($lineData['rate']) )  {
                continue;
            }
                    
            // scan for missing elements in line
            if ( (empty($lineData['item']) || empty($lineData['qty']) || empty($lineData['rate'])) ) {
                $this->context->buildViolation($constraint->incompleteMessage)
                    ->setParameter('%string%', ($lineNum+1))
                    ->addViolation();            
            }
            
            // check that quantity is numeric
            if (!is_numeric($lineData['qty'])) {
                $this->context->buildViolation($constraint->invalidQuantityMessage)
                    ->setParameter('%string%', ($lineNum+1))
                    ->addViolation();                        
            }
            
            // check that rate is numeric
            if (!is_numeric($lineData['rate'])) {
                $this->context->buildViolation($constraint->invalidRateMessage)
                    ->setParameter('%string%', ($lineNum+1))
                    ->addViolation();                        
            }
            
        }
    }
}
