<?php
function validCoverUrl(string $url): bool {return $url===''||(strlen($url)<=1000&&validMenuUrl($url));}
function postCover(array $post): string {
    $cover=trim((string)($post['cover_url']??''));if($cover!==''&&validCoverUrl($cover))return $cover;
    if(preg_match('~!\[[^\]]*\]\(\s*(?:<([^>\r\n]+)>|([^\s)]+))~',(string)($post['markdown']??''),$match)){
        $url=$match[1]!==''?$match[1]:($match[2]??'');if($url!==''&&validCoverUrl($url))return $url;
    }
    return '';
}
function coverDimensions(string $url): ?array {
    if(!preg_match('~^/uploads/[a-f0-9]{32}\.(?:jpg|png|gif|webp)$~',$url))return null;
    return @getimagesize(dirname(__DIR__).'/public'.$url)?:null;
}
