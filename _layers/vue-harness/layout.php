<?php

$data = [];
if ($this->fileExists('package.json')) {  
    $data = json_decode($this->fileGetContents('package.json'), 1);
} 
$title = $GLOBALS['PAGE_TITLE'] ?? $GLOBALS['VUE_HARNESS_BRAND_NAME'] ?? $data['name'] ?? pathinfo($this->getPath(), PATHINFO_FILENAME);
require_once 'color-functions.php';
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <link rel="shortcut icon" href="#" />
        <title><?= $title ?? '' ?></title>        
        <link rel="stylesheet" href="dist/vue-harness.css">

        <style>
        html {
            padding: 0;
            margin: 0;
        }
        body {
            overflow: auto;
            margin: 0;
            padding: 30px;
            background: <?= $GLOBALS['VUE_HARNESS_COLOR'] ?? adjustBrightness(stringToColor($title ?? 'no-title'),0.7) ?>;
        }
        body .main-nav { 
            border-radius: 10px;
            background: white;
        }

        body .main-container {
            border-radius: 10px;
            background: white;
            margin-top: 10px;
            padding: 10px;
            padding-top: 20px;
        }
        .container-fluid {
            background: transparent;
        }

       
    </style>
    </head>
    <body class="vue-harness-body">
        <?php if (!preg_match('~<app\s*>~', $content)): ?>
            <app></app>
        <?php endif; ?>
        
        <?php if (!preg_match('~<template\s+component="app"~', $content)): ?>
        <template component="app">
            <div>
                <nav class="main-nav navbar nav navbar-expand">
                    <div class="navbar-brand">
                        <slot name="brand">
                        <?= $brandExtra ?? '' ?>
                        <?= $title ?? '' ?>
                        </slot>         
                    </div>
                    <div class="navbar-nav">
                        <slot name="nav-before"></slot>
                        <slot name="navbar">
                            <router-link 
                            class="nav-link"
                            v-for="r in $router.options.routes"
                                v-if="shouldShowInMenu(r)"
                            :to="r.path">
                                <i v-if="r.icon" class="fa" :class="r.icon"></i>
                                <span v-if="r.name || r.title || r.caption">{{r.name || r.title || r.caption}}</span>
                                <span v-else-if="r.path == '/'"><i class="fa fa-home"></i>&nbsp;</span>
                                <span v-else>{{r.path}}</span>
                            </router-link>
                        </slot>
                    </div>
                    <slot name="nav-extra"></slot>
                </nav>
                <div class="container-fluid main-container">
                    <?php 
                    if (preg_match('~<template.*url=~', $content)) {
                        $appComponent = '<router-view></router-view>';
                    } else if (preg_match('~<template.*component="([^"]+)">~', $content, $m)) {
                        $appComponent = "<" . $m[1] . "></" . $m[1] . ">\n";
                    } else {
                        $appComponent = '<em>There where no template component or template url blocks to show you.</em>';
                    }
                    echo $appComponent;
                    ?>
                </div>
            </div>
            <script>
                export default {
                    mounted(){
                    },
                    methods: {
                        shouldShowInMenu(route) { 
                            const isDynamicRoute = route.path.match(/\/:[a-z]+/);
                            const hideOnRequest = (route.name || route.title || route.caption) =="(hidden)";
                            return !isDynamicRoute && !hideOnRequest;
                        }
                    }
                }
            </script>
        </template>
	<?php endif; ?>

        <script src="dist/vue-harness.js"></script>
        <?php if (file_exists('dist/bundle.js')): ?>
            <script src="/dist/bundle.js"></script>
        <?php endif; ?>
        
        <?php echo $content ?>

        <!-- @inserts cdn -->
        
        <!-- @endinserts -->



        <?php if (file_exists('dist/bundle.css')): ?>
            <link rel="stylesheet" href="/dist/bundle.css">
        <?php endif; ?>

        <script>
            /** link-to-storage requires this */
            window.APP_NAME = <?= json_encode($title) ?>;

            // reduce height with 10 pixels to prevent body scrolling.
            window.FULLHEIGHT_OFFSET = 10;
            Vue.prototype.PACKAGE_JSON = <?= json_encode($data); ?>

            // Check if the server is still alive
            // after you've been somewhere else.
            <?php if (!isset($_ENV['HARNESS_EMBEDDED'])): ?>
                setTimeout(() => {
                    var lastCheck = new Date;
                    window.onfocus = async function (event) {
                        if ((new Date) - lastCheck < 30 * 1000) {
                            return;
                        }
                        try { 
                            lastCheck = new Date;
                            await axios.get('/__alive__');
                        } catch (e) {
                            dialog.dialog(`
                                <div width=500 title="Dead server" height=200 centered=true modal=true>
                                    <p style="padding: 15px;text-align: center;">
                                        <i 
                                            class="fa fa-exclamation-triangle" 
                                            style="font-size: 20px;"
                                        ></i>
                                        Please restart the server or close this tab...
                                    </p>
                                </div>
                            `)
                            window.onfocus = function() {}
                        }
                    }
                }, 60 * 1000);
                
            <?php endif; ?>

        </script>
    </body>
</html>
