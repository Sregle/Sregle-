<?php
/**
 * Universal GitHub Plugin Updater
 *
 * Drop this file in your plugin folder and include it in your main plugin file.
 */

if ( ! class_exists( 'Puc_v4_Factory' ) ) {
    require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! function_exists( 'sregle_register_updater' ) ) {
    /**
     * Register updater for a plugin
     *
     * @param string $repoUrl GitHub repository URL.
     * @param string $pluginFile Main plugin file (__FILE__).
     * @param string $slug Plugin folder slug.
     * @param string|null $token Optional GitHub token for private repos.
     */
    function sregle_register_updater( $repoUrl, $pluginFile, $slug, $token = null ) {
        $updateChecker = PucFactory::buildUpdateChecker(
            $repoUrl,
            $pluginFile,
            $slug
        );

        if ( $token ) {
            $updateChecker->setAuthentication( $token );
        }

        $updateChecker->getVcsApi()->enableReleaseAssets();
    }
}
