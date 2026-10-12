<?php

namespace MesCore\Production\Calc;

// 계산식 등록 오류 — code 는 자재소요-계산-설계 §6.4 의 E_* ( E_TOKEN · E_PAREN · E_SYNTAX · E_FUNC · E_VAR · E_LIMIT )
class FormulaException extends \RuntimeException {

    public function __construct(public readonly string $errorCode, string $message) {
        parent::__construct($message);
    }
}
