<?php

function validateAssetSymbol($symbol) {
    return preg_match('/^[A-Z0-9]{1,32}$/', $symbol);
}

function validateNetworkSymbol($symbol) {
    return preg_match('/^[A-Z0-9_]{1,32}$/', $symbol);
}

?>