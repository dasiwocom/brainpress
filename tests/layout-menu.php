<?php
putenv('BRAINPRESS_DSN=sqlite::memory:');require dirname(__DIR__).'/src/bootstrap.php';
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function rejects(callable $action,string $message): void {try{$action();}catch(InvalidArgumentException){echo "PASS $message\n";return;}throw new RuntimeException($message);}
$regions=array_keys(layoutZones());check(count($regions)===7,'Seven layout regions are available');
$items=array_map(fn($zone)=>['type'=>'profile','zone'=>$zone,'name'=>$zone],$regions);$clean=validateLayout($items,'home');check(array_column($clean,'zone')===$regions,'Existing profile widgets can populate every region');
saveSetting('layout:home',json_encode($clean));check(count(layoutItems('home'))===7,'All region settings survive persistence');
rejects(fn()=>validateLayout([['type'=>'text','zone'=>'main']],'home'),'No Markdown text widget is introduced');
rejects(fn()=>validateLayout([['type'=>'toc','zone'=>'footer']],'article'),'Contents remains in article sidebar');
rejects(fn()=>validateLayout([['type'=>'posts','zone'=>'sidebar']],'home'),'Post lists cannot occupy sidebars');
rejects(fn()=>validateLayout([['type'=>'posts','zone'=>'main'],['type'=>'posts','zone'=>'bottom_full']],'home'),'A single post list owns pagination');
$entries=validateMenuContent([['label'=>'Plain <script>text</script>','url'=>''],['label'=>'Docs','url'=>'https://example.com/docs']]);check(count($entries)===2&&$entries[0]['url']==='','Submenus accept plain text and linked text');
foreach(['javascript:alert(1)','//evil.test',"/bad\nheader"] as $url)rejects(fn()=>validateMenuContent([['label'=>'Bad','url'=>$url]]),'Unsafe submenu link is rejected');
$menu=[['id'=>'root','parent'=>'','label'=>'Root','url'=>'/','visible'=>true],['id'=>'child','parent'=>'root','label'=>'Column','url'=>'','visible'=>true,'content'=>$entries]];
validateMenuParents($menu);saveSetting('top_menu',json_encode($menu));check(count(menuGroups())===1&&count(menuGroups()[0]['children'])===1,'Second-level headings form columns under their root');
ob_start();renderHeaderMenu();$html=ob_get_clean();check(str_contains($html,'class="mega-column"')&&str_contains($html,'&lt;script&gt;'),'Panel renders headings and safely escapes text');check(str_contains($html,'<span class="">Plain')&&str_contains($html,'href="https://example.com/docs"'),'Empty URLs render spans; linked text renders anchors');
$menu[0]['visible']=false;saveSetting('top_menu',json_encode($menu));check(menuGroups()===[],'Hiding a root hides its submenu columns');
