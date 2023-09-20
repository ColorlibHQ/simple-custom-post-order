module.exports = function(grunt) {
  // load all tasks
  require('load-grunt-tasks')(grunt, {scope: 'devDependencies'});

  grunt.initConfig({
    pkg: grunt.file.readJSON('package.json'),

    dirs: {
        js: '/assets'
    },
    checktextdomain: {
        standard: {
            options:{
                text_domain: [ 'simple-custom-post-order' ], //Specify allowed domain(s)
                create_report_file: "true",
                keywords: [ //List keyword specifications
                    '__:1,2d',
                    '_e:1,2d',
                    '_x:1,2c,3d',
                    'esc_html__:1,2d',
                    'esc_html_e:1,2d',
                    'esc_html_x:1,2c,3d',
                    'esc_attr__:1,2d',
                    'esc_attr_e:1,2d',
                    'esc_attr_x:1,2c,3d',
                    '_ex:1,2c,3d',
                    '_n:1,2,4d',
                    '_nx:1,2,4c,5d',
                    '_n_noop:1,2,3d',
                    '_nx_noop:1,2,3c,4d'
                ]
            },
            files: [{
                src: [
                    '**/*.php',
                    '!**/node_modules/**',
                ], //all php
                expand: true,
            }],
        }
    },
    makepot: {
          target: {
              options: {
                  cwd: '',                          // Directory of files to internationalize.
                  domainPath: 'languages/',         // Where to save the POT file.
                  exclude: [],                      // List of files or directories to ignore.
                  include: [],                      // List of files or directories to include.
                  mainFile: 'simple-custom-post-order.php',                     // Main project file.
                  potComments: '',                  // The copyright at the beginning of the POT file.
                  potFilename: 'simple-custom-post-order.po',                  // Name of the POT file.
                  potHeaders: {
                      poedit: true,                 // Includes common Poedit headers.
                      'x-poedit-keywordslist': true // Include a list of all possible gettext functions.
                  },                                // Headers to add to the generated POT file.
                  processPot: null,                 // A callback function for manipulating the POT file.
                  type: 'wp-plugin',                // Type of project (wp-plugin or wp-theme).
                  updateTimestamp: true,            // Whether the POT-Creation-Date should be updated without other changes.
                  updatePoFiles: false              // Whether to update PO files in the same directory as the POT file.
              }
          }
      },
    clean: {
        init: {
            src: ['build/']
        },
        build: {
            src: [
                'build/*',
                '!build/<%= pkg.name %>.zip'
            ]
        },
        jsmin : {
				src: [
					'assets/js/*.min.js',
					'assets/js/front/*.min.js',
				]
			}
    },
    copy: {
      build: {
          expand: true,
          src: [
              '**',
              '!node_modules/**',
              '!vendor/**',
              '!build/**',
              '!readme.md',
              '!README.md',
              '!phpcs.ruleset.xml',
              '!Gruntfile.js',
              '!package.json',
              '!package-lock.json',
              '!composer.json',
              '!composer.lock',
              '!set_tags.sh',
              '!simple-custom-post-order.zip',
              '!nbproject/**' ],
          dest: 'build/'
      }
    },
    uglify: {
		options: {
		  compress: {
		    global_defs: {
		      'DEBUG': true
		    },
		    dead_code: true
		  }
		},
		jsfiles: {
			files: [ {
				expand: true,
				cwd   : 'assets/',
				src   : [
					'*.js',
                    '!*.min.js',
				],
				dest  : 'assets/',
				ext   : '.min.js'
			} ]
		}
	},
    compress: {
        build: {
            options: {
                pretty: true,                           // Pretty print file sizes when logging.
                archive: '<%= pkg.name %>.zip'
            },
            expand: true,
            cwd: 'build/',
            src: ['**/*'],
            dest: '<%= pkg.name %>/'
        }
    },
  });

  grunt.registerTask( 'i18n', ['checktextdomain', 'makepot']);
  // Build task
  grunt.registerTask( 'build-archive', [
      // 'i18n',
      'clean:init',
      'copy',
      'compress:build',
      'clean:init'
  ]);
  grunt.registerTask( 'minjs', [  // Minify CSS
		'clean:jsmin',
		'uglify',
	] );
};