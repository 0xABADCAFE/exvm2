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

    private const MAX_VARIANTS       = 16;
    private const EXT_WORD_SIZE      = 16;
    private const OPERAND_FIELD_SIZE = 8;

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

        foreach ($this->aOpcodeDefs as $sKey => $oOpcodeDef) {
            echo $sKey, ":\n";
            if (empty($oOpcodeDef->variants)) {

                foreach ($this->expressTypedForms($oOpcodeDef) as $sForm) {
                    echo "\t", $sForm, "\n";
                }

            } else {
                foreach ($oOpcodeDef->variants as $oVariantDef) {
                    foreach ($this->expressTypedForms($oVariantDef) as $sForm) {
                        echo "\t", $sForm, " [variant ", $oVariantDef->variation, "]\n";
                    }
                }
            }
        }

    }

    private function expressTypedForms(\stdClass $oOpcodeDef): array {

        $sExpand = str_replace(
            [
                '{r}',
                '{f}',
            ],
            [
                'r<N>',
                'f<N>',
            ],
            $oOpcodeDef->form
        );

        $aResult = [];

        if (!empty($oOpcodeDef->comparison)) {
            foreach ($this->aComparisonDefs as $oComparison) {
                foreach ($oComparison->types as $sType) {
                    $sTemp = str_replace(
                        [
                            '{c}',
                            '{t}', // operand type
                        ],
                        [
                            $oComparison->form,
                            (string)$sType,
                        ],
                        $sExpand
                    );
                    $aResult[] = str_replace(
                        [
                            '{r}',
                            '{f}',
                        ],
                        [
                            'r<N>',
                            'f<N>',
                        ],
                        $sTemp
                    );
                }
            }
        } else {

            $aTypes = $oOpcodeDef->types ?? [];
            if (empty($aTypes)) {
                return [$sExpand];
            } else {
                foreach ($aTypes as $sType) {
                    $aResult[] = str_replace(
                        [
                            '{t}', // operand type
                            '{z}', // shrink-fit integer immediate (may be smaller than the operand size)
                            '{fz}' // shrink-fit float immediate (may be smaller than tye operand size)
                        ],
                        [
                            (string)$sType,
                            $oOpcodeDef->fitsize ?? "!",
                            isset($oOpcodeDef->fitsize) ? ($oOpcodeDef->fitsize . '.0') : "!"
                        ],
                        $sExpand
                    );
                }
            }
        }
        return $aResult;
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

        $aDefs       = (array)$aDefs[self::DEF_OPCODES]->data->opcodedefs;
        $aOpcodeDefs = [];
        foreach ($aDefs as $sDefName) {
            $oData = $this->loadDef('opcodes/' . $sDefName . '.json');
            foreach ($oData->data->opcodes as $sKey => $oOpcodeDef) {
                $aOpcodeDefs[$sKey] = $oOpcodeDef;
            }
        }


        unset($aOpcodeDefs[self::DEF_LIST_END]);

        $aMerged = [];
        foreach ($aOpcodeDefs as $sOpcodeID => $oPartDef) {
            $oOpcodeDef = clone $oDefaultDef;
            foreach ($oPartDef as $sKey => $mValue) {
                $oOpcodeDef->{$sKey} = $mValue;
            }

            if (empty($oOpcodeDef->unsized) && empty($oOpcodeDef->variant)) {
                $this->associateTypes($oOpcodeDef);
            }

            $this->validateOpcodeDef($sOpcodeID, $oOpcodeDef);

            $aMerged[$sOpcodeID] = $oOpcodeDef;

            if (!empty($oOpcodeDef->variants)) {
                // Convert each variant into a fully fledged definition and validate it
                foreach ($oOpcodeDef->variants as $iVariantID => $oVariantPartDef) {
                    $oVariantDef = clone $oOpcodeDef;
                    unset($oVariantDef->variants);
                    unset($oVariantDef->variant);
                    $oVariantDef->variation = $iVariantID;

                    foreach ($oVariantPartDef as $sKey => $mValue) {
                        $oVariantDef->{$sKey} = $mValue;
                    }

                    if (empty($oVariantDef->unsized)) {
                        $this->associateTypes($oVariantDef);
                    }

                    $this->validateOpcodeDef($sOpcodeID, $oVariantDef);

                    // Replace the part definition with the full, validated version
                    $oOpcodeDef->variants[$iVariantID] = $oVariantDef;
                }
            }

        }

        return $aMerged;
    }

    private function associateTypes(\stdClass $oOpcodeDef) {
        if (!empty($oOpcodeDef->sizes)) {
            $oOpcodeDef->sizes = array_combine($oOpcodeDef->sizes, $oOpcodeDef->sizes);
        }
        if (!empty($oOpcodeDef->types)) {
            $oOpcodeDef->types = array_combine($oOpcodeDef->types, $oOpcodeDef->types);
        }
        if (!empty($oOpcodeDef->sizetype)) {
            $oOpcodeDef->types = $oOpcodeDef->sizes + $oOpcodeDef->types;
        }
    }

    private function validateOperandSet(array $aOperands, int $iSize, int &$iVariantDefCount) {
        $iBits = 0;
        foreach ($aOperands as $sKey => $sOperandType) {
            if (!isset($this->aOperandDefs[$sOperandType])) {
                throw new RuntimeException('Invalid Operand Type ' . $sOperandType . ' for ' . $sKey);
            }
            if ($sOperandType === 'VariantID') {
                if (++$iVariantDefCount > 1) {
                    throw new RuntimeException('VariantID defined more than once.');
                }
            }

            $iBits += $this->aOperandDefs[$sOperandType]->bits;
        }
        if ($iBits !== $iSize) {
            throw new RuntimeException('Invalid Operand Set size ' . $iBits . ', expected ' . $iSize);
        }
    }

    private function validateOpcodeDef(string $sOpcodeID, \stdClass $oOpcodeDef) {
        $iVariantDefCount = 0;
        $this->validateOperandSet(
            [
                $oOpcodeDef->opA ?? null,
                $oOpcodeDef->opB ?? null
            ],
            self::OPERAND_FIELD_SIZE,
            $iVariantDefCount
        );

        if (!empty($oOpcodeDef->extwords)) {
            foreach ($oOpcodeDef->extwords as $oOperandSet) {
                $this->validateOperandSet((array)$oOperandSet, self::EXT_WORD_SIZE, $iVariantDefCount);
            }
        }

        if (!empty($oOpcodeDef->variant)) {
            if (empty($oOpcodeDef->variants) || !is_array($oOpcodeDef->variants)) {
                throw new RuntimeException('Missing/Invalid variants definition for ' . $sOpcodeID);
            }
            if (count($oOpcodeDef->variants) > self::MAX_VARIANTS) {
                throw new RuntimeException('Too many variant definitions for ' . $sOpcodeID);
            }
        }
    }
}

$oSpec = new Spec('./');

