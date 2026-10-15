<?php
function normalizeSiteUrl(string $url): string {
    $url=trim($url);if($url==='')return '';
    $parts=parse_url($url);
    if(strlen($url)>1000||!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['path']??'',['','/'],true)||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new InvalidArgumentException('Use a complete HTTP(S) site address without a path, query or credentials.');
    return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port'])?':'.$parts['port']:'');
}
function siteUrl(): string {
    $configured=setting('site_url');if($configured!==''){try{return normalizeSiteUrl($configured);}catch(InvalidArgumentException){}}
    $scheme=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http';$host=$_SERVER['HTTP_HOST']??'localhost';
    if(!is_string($host))return 'http://localhost';try{return normalizeSiteUrl($scheme.'://'.$host);}catch(InvalidArgumentException){return 'http://localhost';}
}
function absoluteSiteUrl(string $path): string {
    if(str_starts_with($path,'/')&&!str_starts_with($path,'//'))return siteUrl().$path;
    return $path;
}
