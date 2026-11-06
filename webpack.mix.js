import mix from 'laravel-mix';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import rtlcss from 'rtlcss';
import webpackSources from 'webpack-sources';

const { RawSource } = webpackSources;
const __dirname = path.dirname(fileURLToPath(import.meta.url));

const folder = {
    src: "./resources/views/",
    src_assets: "./resources/assets/",
    dist: "./public/",
    dist_assets: "./public/assets/"
};

const cssPairs = [
    { ltr: 'css/app.min.css', rtl: 'css/app-rtl.min.css' },
    { ltr: 'css/bootstrap.min.css', rtl: 'css/bootstrap-rtl.min.css' },
];

mix
    // Compile SCSS files
    .sass(path.join(folder.src_assets, 'scss/app.scss'), 'css/app.min.css')
    .sass(path.join(folder.src_assets, 'scss/bootstrap.scss'), 'css/bootstrap.min.css')
    .sass(path.join(folder.src_assets, 'scss/icons.scss'), 'css/icons.min.css')

    // Compile JS
    .js(path.join(folder.src_assets, 'js/app.js'), 'js/app.js')

    // Copy assets
    .copyDirectory(path.join(folder.src_assets, 'images'), path.join(folder.dist_assets, 'images'))
    .copyDirectory(path.join(folder.src_assets, 'js'), path.join(folder.dist_assets, 'js'))
    .copyDirectory(path.join(folder.src_assets, 'fonts'), path.join(folder.dist_assets, 'fonts'))
    .copyDirectory(path.join(folder.src_assets, 'libs'), path.join(folder.dist_assets, 'libs'))

    // BrowserSync
    .browserSync({
        proxy: false,
        server: {
            baseDir: folder.dist
        },
        host: 'localhost',
        port: 3000,
        open: true,
        notify: false
    })

    // Set resource root for correct relative paths in CSS (e.g., ../fonts/)
    .setResourceRoot('../')

    // Set public path for compiled assets
    .setPublicPath(folder.dist_assets)

    // Fix CSS URL rewriting and specify asset directories
    .options({
        processCssUrls: true,
        fileLoaderDirs: {
            fonts: 'fonts',
            images: 'images'
        }
    })

    // Disable success notifications
    .disableSuccessNotifications()

    // Webpack config
    .webpackConfig({
        plugins: [
            {
                apply(compiler) {
                    compiler.hooks.thisCompilation.tap('GenerateRTL', (compilation) => {
                        compilation.hooks.processAssets.tap(
                            {
                                name: 'GenerateRTL',
                                stage: compilation.PROCESS_ASSETS_STAGE_ADDITIONAL,
                            },
                            () => {
                                cssPairs.forEach((pair) => {
                                    if (compilation.assets[pair.ltr]) {
                                        const ltrCss = compilation.assets[pair.ltr].source();
                                        const rtlCss = rtlcss.process(ltrCss, {
                                            autoRename: false,
                                            clean: false
                                        });
                                        compilation.emitAsset(pair.rtl, new RawSource(rtlCss));
                                    }
                                });
                            }
                        );
                    });
                },
            }
        ],

        output: {
            path: path.resolve(__dirname, folder.dist_assets),
            filename: 'chunk/[name].js',
            chunkFilename: 'chunk/[name].[chunkhash].js',
            publicPath: '../',
        },

        optimization: {
            splitChunks: {
                chunks: 'all',
                cacheGroups: {
                    default: {
                        minChunks: 2,
                        priority: -20,
                        reuseExistingChunk: true,
                    },
                    vendor: {
                        test: /[\\/]node_modules[\\/]/,
                        name: 'vendors',
                        priority: -10,
                        chunks: 'all',
                    },
                },
            },
        },

        performance: {
            hints: false,
        },

        devServer: {
            static: {
                directory: path.join(__dirname, folder.dist),
                serveIndex: true,
            },
            hot: "only",
        }
    });

// Environment-specific options
if (mix.inProduction()) {
    mix.version();
} else {
    mix.sourceMaps();
}
