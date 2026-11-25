<?php
/**
 * 测试站安装:WordPress 安装、启用插件、生成测试数据。
 * 用法:php install.php <站点目录> <工作目录>
 */
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = 'localhost:8899';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install( 'Test', 'admin', 'admin@example.com', true, '', 'pass' );
update_option( 'siteurl', 'http://localhost:8899' );
update_option( 'home', 'http://localhost:8899' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'active_plugins', array( 'sqlite-database-integration/load.php' ) );
echo "installed\n";
