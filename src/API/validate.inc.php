<?php

function validateEmail($mail) {
    if(strlen($mail) > 254) return false;
    return preg_match('/^\\w+([\\.\\+-]?\\w+)*@\\w+([\\.-]?\\w+)*(\\.\\w{2,24})+$/', $mail);
}

function validatePassword($pw) {
    if(strlen($pw) < 8) return false;
    if(strlen($pw) > 254) return false;
    if(!preg_match('#[A-Z]+#', $pw)) return false;
    if(!preg_match('#[a-z]+#', $pw)) return false;
    if(!preg_match('#[0-9]+#', $pw)) return false;
    return true;
}

function validateVeriCode($code) {
    return preg_match('/^[0-9]{6}$/', $code);
}

function validateCaptchaChal($captcha) {
    return preg_match('/^[a-f0-9]{32}$/', $captcha);
}

function validateCaptchaResp($captcha) {
    return preg_match('/^[a-np-zA-NP-Z1-9]{4}$/', $captcha);
}

function validateApiKey($apiKey) {
    return preg_match('/^[a-f0-9]{64}$/', $apiKey);
}

function validateAssetSymbol($symbol) {
    return preg_match('/^[A-Z0-9]{1,32}$/', $symbol);
}

function validateNetworkName($network) {
    return preg_match('/^[A-Z0-9_]{1,32}$/', $network);
}

function validateFloat($float) {
    if(gettype($float) != 'string') return false;
    return preg_match('/^[0-9]{1,33}(\.[0-9]{1,32})?$/', $float);
}

function validateAdbkName($name) {
    return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $name);
}

function validateApiKeyDescription($desc) {
    return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $desc);
}

function validatePairName($pair) {
    return preg_match('/^[A-Z0-9]{1,32}\/[A-Z0-9]{1,32}$/', $pair);
}

function filterAssetSearch($search) {
    return preg_replace('/[^a-zA-Z0-9 \/]/', '', $search);
}

function validateReflinkDescription($desc) {
    return preg_match('/^[a-zA-Z0-9 ]{1,255}$/', $desc);
}

function validateAnnouncementId($id) {
    if(preg_match('/^[0-9]{1,6}$/', $id)) return 1;
    if(preg_match('/^[a-z0-9\-]{1,255}$/', $id)) return 2;
    return 0;
}

function validateTransferMessage($msg) {
    return preg_match('/^[a-zA-Z0-9 _,@#%\.\\\\\/\+\?\[\]\$\(\)\=\!\:\-]{1,255}$/', $msg);
}

function filterVotingAssetName($name) {
    return preg_replace('/[^a-zA-Z0-9 \-\.]/', '', $name);
}

function validateVotingWebsite($website) {
    if(strlen($website) > 255) return false;
    return preg_match('/^(https?:\/\/)?([a-z0-9\-]+\.)+[a-z]{2,20}(\/[a-z0-9\-\.]+)*\/?$/', $website);
}

function validate2FA($code) {
    return preg_match('/^[0-9]{4,20}$/', $code);
}

?>