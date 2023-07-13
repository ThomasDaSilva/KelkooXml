<?php

namespace KelkooXml\Tools;

class EanChecker
{
    public function isValidEan($gtin): bool
    {
        if (!is_numeric($gtin)) {
            return false;
        } elseif (!in_array(strlen($gtin), array(6, 8, 12, 13, 14))) {
            return false;
        }

        // TODO : better check
        return true;
    }
}
