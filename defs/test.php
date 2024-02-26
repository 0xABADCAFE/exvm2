<?php

declare(strict_types=1);

namespace ABadCafe\ExVM2;

use RuntimeException;

use function \is_dir, \is_file, \is_readable, \json_decode, \file_get_contents;

class Spec {

    private const DEF_TYPES       = 'types';
    private const DEF_CLASSES     = 'classes';
    private const DEF_OPERANDS    = 'operands';
    private const DEF_LEVELS      = 'levels';
    private const DEF_COMPARISONS = 'comparisons';
    private const DEF_OPCODES     = 'opcodes';
    private const DEF_LIST_END    = '_end';

    private const DEFS = [
        self::DEF_TYPES,
        self::DEF_CLASSES,
        self::DEF_OPERANDS,
        self::DEF_LEVELS,
        self::DEF_COMPARISONS,
        self::DEF_OPCODES,
    ];

    private array $aTypeDefs       = [];
    private array $aClassDefs      = [];
    private array $aOperandDefs    = [];
    private array $aLevelDefs      = [];
    private array $aComparisonDefs = [];
    private array $aOpcodeDefs     = [];

    public function __construct(string $str_path) {
        if (!is_dir($str_path) || !is_readable($str_path)) {
            throw new RuntimeException('Invalid definition path ' . $str_path);
        }

        $aDefs = [];
        foreach (self::DEFS as $str_def) {
            $aDefs[$str_def] = $this->loadDef($str_path . '/' . $str_def . '.json');
        }

        $this->aTypeDefs       = $this->parseTypeDefs($aDefs);
        $this->aClassDefs      = $this->parseClassDefs($aDefs);
        $this->aOperandDefs    = $this->parseOperandDefs($aDefs);
        $this->aLevelDefs      = $this->parseLevelDefs($aDefs);
        $this->aComparisonDefs = $this->parseComparisonDefs($aDefs);
        $this->aOpcodeDefs     = $this->parseOpcodeDefs($aDefs);

        printf(
            "Parsed:\n\t%d TypeDefs\n\t%d ClassDefs\n\t%d OperandDefs\n\t%d LevelDefs\n\t%d ComparisonDefs\n\t%d OpcodeDefs\n",
            count($this->aTypeDefs),
            count($this->aClassDefs),
            count($this->aOperandDefs),
            count($this->aLevelDefs),
            count($this->aComparisonDefs),
            count($this->aOpcodeDefs)
        );
    }

    private function loadDef(string $str_def): \stdClass {
        $oDef = null;
        if (
            !is_file($str_def) ||
            !is_readable($str_def)
        ) {
            throw new RuntimeException('Could not read ' . $str_def);
        }
        if (!($oDef = json_decode(file_get_contents($str_def)))) {
            throw new RuntimeException(
                'Could not parse JSON ' . $str_def . ', error: ' . json_last_error_msg()
            );
        }
        if (empty($oDef->data)) {
            throw new RuntimeException('Missing data section in ' . $str_def);
        }

        return $oDef;
    }

    private function parseTypeDefs(array $aDefs): array {
        $aDefs = (array)$aDefs[self::DEF_TYPES]->data->types;
        unset($aDefs[self::DEF_LIST_END]);
        return $aDefs;
    }

    private function parseClassDefs(array $aDefs): array {
        $aDefs = (array)$aDefs[self::DEF_CLASSES]->data->classes;
        unset($aDefs[self::DEF_LIST_END]);
        return $aDefs;
    }

    private function parseOperandDefs(array $aDefs): array {
        $aDefs = (array)$aDefs[self::DEF_OPERANDS]->data->operands;
        unset($aDefs[self::DEF_LIST_END]);
        return $aDefs;
    }

    private function parseLevelDefs(array $aDefs): array {
        $aDefs = (array)$aDefs[self::DEF_LEVELS]->data->levels;
        unset($aDefs[self::DEF_LIST_END]);

        $aMerged = [];
        $oMerged = (object)[
            'sizes' => [],
            'types' => [],
            'addr'  => 0
        ];
        foreach ($aDefs as $sLevelID => $oPartDef) {
            if (!empty($oPartDef->sizes)) {
                foreach ($oPartDef->sizes as $iSize) {
                    $oMerged->sizes[$iSize]= $iSize;
                }
            }
            if (!empty($oPartDef->types)) {
                foreach ($oPartDef->types as $sType) {
                    $oMerged->types[$sType]= $sType;
                }
            }
            if (
                !empty($oPartDef->addr) &&
                $oPartDef->addr > $oMerged->addr
            ) {
                $oMerged->addr = $oPartDef->addr;
            }

            $aMerged[$sLevelID] = clone $oMerged;
        }
        return $aMerged;
    }

    private function parseComparisonDefs(array $aDefs): array {
        $oDefaultDef = $aDefs[self::DEF_COMPARISONS]->data->default;
        $aDefs       = (array)$aDefs[self::DEF_COMPARISONS]->data->comparisons;
        unset($aDefs[self::DEF_LIST_END]);

        $aMerged = [];
        foreach ($aDefs as $sComparisonID => $oPartDef) {
            $oComparisonDef = clone $oDefaultDef;
            foreach ($oPartDef as $sKey => $mValue) {
                $oComparisonDef->{$sKey} = $mValue;
            }

            $oComparisonDef->sizes = array_combine($oComparisonDef->sizes, $oComparisonDef->sizes);
            $oComparisonDef->types = array_combine($oComparisonDef->types, $oComparisonDef->types);

            $this->validateComparisonDef($sComparisonID, $oComparisonDef);

            $aMerged[$sComparisonID] = $oComparisonDef;
        }

        return $aMerged;
    }

    private function validateComparisonDef(string $sComparisonID, \stdClass $oComparisonDef) {
        if (
            empty($oComparisonDef->level) ||
            !isset($this->aLevelDefs[$oComparisonDef->level])
        ) {
            throw new RuntimeException('Missing/Invalid level definition for comparison ' . $sComparisonID);
        }
        if (
            empty($oComparisonDef->opA) ||
            !isset($this->aOperandDefs[$oComparisonDef->opA])
        ) {
            throw new RuntimeException('Missing/Invalid opA definition for comparison ' . $sComparisonID);
        }
        if (
            empty($oComparisonDef->opB) ||
            !isset($this->aOperandDefs[$oComparisonDef->opB])
        ) {
            throw new RuntimeException('Missing/Invalid opB definition for comparison ' . $sComparisonID);
        }
        $oLevelDef = $this->aLevelDefs[$oComparisonDef->level];
        if (empty($oComparisonDef->sizes)) {
            throw new RuntimeException('Missing sizes definition for comparison ' . $sComparisonID);
        }
        foreach ($oComparisonDef->sizes as $iSize) {
            if (!isset($oLevelDef->sizes[$iSize])) {
                throw new RuntimeException('Size definition ' . $iSize . ' for comparison ' . $sComparisonID . ' is incompatible with level ' . $oComparisonDef->level);
            }
        }
        foreach ($oComparisonDef->types as $sType) {
            if (!isset($oLevelDef->types[$sType])) {
                throw new RuntimeException('Type definition ' . $sType . ' for comparison ' . $sComparisonID . ' is incompatible with level ' . $oComparisonDef->level);
            }
        }
    }

    private function parseOpcodeDefs(array $aDefs): array {
        $oDefaultDef = $aDefs[self::DEF_OPCODES]->data->default;
        $aDefs       = (array)$aDefs[self::DEF_OPCODES]->data->opcodes;
        unset($aDefs[self::DEF_LIST_END]);

        $aMerged = [];
        foreach ($aDefs as $sOpcodeID => $oPartDef) {
            $oOpcodeDef = clone $oDefaultDef;
            foreach ($oPartDef as $sKey => $mValue) {
                $oOpcodeDef->{$sKey} = $mValue;
            }

            if (empty($oOpcodeDef->unsized) && empty($oOpcodeDef->variant)) {
                $oOpcodeDef->sizes = array_combine($oOpcodeDef->sizes, $oOpcodeDef->sizes);
                $oOpcodeDef->types = array_combine($oOpcodeDef->types, $oOpcodeDef->types);
            }
            $this->validateOpcodeDef($sOpcodeID, $oOpcodeDef);

            $aMerged[$sOpcodeID] = $oOpcodeDef;
        }

        return $aMerged;
    }

    private function validateOpcodeDef(string $sOpcodeID, \stdClass $oOpcodeDef) {
        if (!isset($oOpcodeDef->opA)) {
            throw new RuntimeException('Missing required operand A definition for ' . $sOpcodeID);
        }
        if (!isset($oOpcodeDef->opB)) {
            throw new RuntimeException('Missing required operand B definition for ' . $sOpcodeID);
        }
        if (!isset($this->aOperandDefs[$oOpcodeDef->opA])) {
            throw new RuntimeException('Invalid operand A definition for ' . $sOpcodeID);
        }
        if (!isset($this->aOperandDefs[$oOpcodeDef->opB])) {
            throw new RuntimeException('Invalid operand B definition for ' . $sOpcodeID);
        }

        $iOperandBits =
            $this->aOperandDefs[$oOpcodeDef->opA]->bits +
            $this->aOperandDefs[$oOpcodeDef->opB]->bits;

        if ($iOperandBits !== 8) {
            throw new RuntimeException("Total operand sizes for A/B must be exactly 8 bits for " . $sOpcodeID);
        }

        if (!empty($oOpcodeDef->variant)) {
            if (empty($oOpcodeDef->variants) || !is_array($oOpcodeDef->variants)) {
                throw new RuntimeException('Missing/Invalid variants definition for ' . $sOpcodeID);
            }
            if (count($oOpcodeDef->variants) > 16) {
                throw new RuntimeException('Too many variant definitions for ' . $sOpcodeID);
            }
        }
    }
}

$oSpec = new Spec('./');

