module.exports = function(grunt) {
  // load all tasks
  require('load-grunt-tasks')(grunt, {scope: 'devDependencies'});

  grunt.initConfig({
    pkg: grunt.file.readJSON('package.json'),

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
                  potFilename: 'simple-custom-post-order.pot',                 // Name of the POT file. (Was '.po', so `grunt i18n` regenerated a mislabelled template and left the real .pot stale — it sat at v2.7.0 while the plugin reached 2.8.6.)
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
              '!tests/**',
              '!README.md',
              '!Gruntfile.js',
              '!package.json',
              '!package-lock.json',
              '!simple-custom-post-order.zip',
              '!Thumbs.db',
              '!CLAUDE.md',
              '!MODERNIZATION-PLAN.md' ],
          dest: 'build/'
      }
    },
    uglify: {
		options: {
		  compress: {
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
    cssmin: {
        target: {
            files: [ {
                expand: true,
                cwd: 'assets/',
                src: [ '*.css', '!*.min.css' ],
                dest: 'assets/',
                ext: '.min.css'
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

  /*
   * Fails the build when the version numbers or release notes disagree:
   * plugin header, SCPORDER_VERSION, readme Stable tag, package.json, the top
   * entries of both changelogs, and an Upgrade Notice (≤ 300 characters, the
   * wordpress.org limit) for the version being released.
   */
  grunt.registerTask( 'release-check', 'Check version numbers and release notes agree', function () {
      var version = grunt.config( 'pkg' ).version;
      var php     = grunt.file.read( 'simple-custom-post-order.php' );
      var readme  = grunt.file.read( 'readme.txt' );
      var log     = grunt.file.read( 'CHANGELOG.md' );
      var errors  = [];
      var found   = {
          'plugin header Version': ( php.match( /^\s*\*\s*Version:\s*(\S+)/m ) || [] )[1],
          'SCPORDER_VERSION': ( php.match( /define\(\s*'SCPORDER_VERSION',\s*'([^']+)'/ ) || [] )[1],
          'readme Stable tag': ( readme.match( /^Stable tag:\s*(\S+)/m ) || [] )[1],
          'readme changelog (top entry)': ( readme.split( '== Changelog ==' )[1] || '' ).match( /=\s*([\d.]+)/ ) ? RegExp.$1 : undefined,
          'CHANGELOG.md (top entry)': ( log.match( /^## \[([^\]]+)\]/m ) || [] )[1]
      };
      Object.keys( found ).forEach( function ( what ) {
          if ( found[ what ] !== version ) {
              errors.push( what + ' is ' + found[ what ] + ', package.json is ' + version );
          }
      } );
      var notices = ( readme.split( '== Upgrade Notice ==' )[1] || '' ).split( /\n== / )[0];
      var re = /^= ([^=]+?) =\n([\s\S]*?)(?=\n= |\s*$)/gm, m, hasNotice = false;
      while ( ( m = re.exec( notices ) ) ) {
          if ( m[1].trim() === version ) { hasNotice = true; }
          if ( m[2].trim().length > 300 ) { errors.push( 'Upgrade Notice ' + m[1] + ' is ' + m[2].trim().length + ' characters (max 300)' ); }
      }
      if ( ! hasNotice ) { errors.push( 'no Upgrade Notice for ' + version ); }
      if ( errors.length ) {
          grunt.fail.warn( errors.join( '\n' ) );
      }
      grunt.log.ok( 'Version ' + version + ' is consistent.' );
  } );

  grunt.registerTask( 'i18n', ['checktextdomain', 'makepot']);
  // Build task: never package stale minified assets, an out-of-date .pot, or
  // mismatched version numbers.
  grunt.registerTask( 'build-archive', [
      'release-check',
      'minjs',
      'i18n',
      'clean:init',
      'copy',
      'compress:build',
      'clean:init'
  ]);
  grunt.registerTask( 'minjs', [  // Minify JS + CSS
		'uglify',
		'cssmin',
	] );
};