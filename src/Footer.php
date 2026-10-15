<?php
function footerSettings(): array {
    $defaults=['show_name'=>true,'show_description'=>true,'copyright'=>'','links'=>[]];$data=json_decode(setting('footer_settings','{}'),true);return is_array($data)?array_replace($defaults,$data):$defaults;
}
function validateFooter(array $data): array {
    if(!is_string($data['copyright']??'')||mb_strlen($data['copyright']??'')>500)throw new InvalidArgumentException('Use up to 500 characters for copyright text.');
    $links=$data['links']??[];if(!is_array($links)||!array_is_list($links)||count($links)>12)throw new InvalidArgumentException('Use up to 12 footer links.');$clean=[];
    foreach($links as $link){if(!is_array($link)||!is_string($link['label']??null)||!is_string($link['url']??null))throw new InvalidArgumentException('Invalid footer link.');$label=trim($link['label']);$url=trim($link['url']);if($label===''&&$url==='')continue;if($label===''||mb_strlen($label)>80||strlen($url)>1000||($url!==''&&!validMenuUrl($url)))throw new InvalidArgumentException('Footer items need text and an optional valid URL.');$clean[]=['label'=>$label,'url'=>$url];}
    return ['show_name'=>!empty($data['show_name']),'show_description'=>!empty($data['show_description']),'copyright'=>trim($data['copyright']??''),'links'=>$clean];
}
function renderFooterInfo(string $siteName): void {
    $footer=footerSettings();$visible=$footer['show_name']||$footer['show_description']||$footer['copyright']!==''||$footer['links'];?>
    <div class="footer-inner footer-settings-inner" <?=$visible?'':'hidden'?>><?php if($footer['show_name']||$footer['show_description']):?><div class="footer-site-info"><?php if($footer['show_name']):?><strong><?=e($siteName)?></strong><?php endif;?><?php if($footer['show_description']):?><span><?=e(setting('description','Powered by Markdown.'))?></span><?php endif;?></div><?php endif;?><div class="footer-extra"><?php if($footer['copyright']!==''):?><p class="footer-copyright"><?=e($footer['copyright'])?></p><?php endif;?><?php if($footer['links']):?><nav class="footer-links" aria-label="<?=e(t('Footer links'))?>"><?php foreach($footer['links'] as $link)menuText($link);?></nav><?php endif;?></div></div>
<?php }
