<?php
putenv('BRAINPRESS_DSN=sqlite::memory:');
require dirname(__DIR__).'/src/bootstrap.php';
function checkLocale(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
checkLocale(language()==='en' && t('Save settings')==='Save settings','English remains the default');
saveSetting('language','zh-CN');checkLocale(language()==='zh-CN' && t('Save settings')==='保存设置','Saved Chinese locale invalidates the request cache');
$source='# Save settings' . "\n\nCategory created.";
checkLocale(str_contains(markdown($source),'Save settings') && str_contains(markdown($source),'Category created.'),'Authored Markdown is never translated');
checkLocale(t('A custom category')==='A custom category','Unknown text is preserved');
checkLocale(str_contains(languageData(),'application/json') && str_contains(languageData(),'i18n.js'),'Client dictionary is JSON data, not executable inline code');
saveSetting('language','en');checkLocale(t('Publish')==='Publish','Switching back to English restores original labels');
