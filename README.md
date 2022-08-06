# OJS-FidusWriter

OJS-FidusWriter is an Open Journal Systems (OJS) plugin to connect an OJS instance with Fidus Writer to from an integrated
publishing system.
This plugin has to be combined with the [FidusWriter-OJS plugin](https://github.com/fiduswriter/fiduswriter-ojs) for Fidus Writer.

Project page:
https://www.fiduswriter.org/ojs-integration/


## Installation:

1. Follow the instructions for the FidusWriter-OJS plugin to install Fidus Writer and the connector on the
   Fidus Writer side: https://github.com/fiduswriter/fiduswriter-ojs

2. Install OJS

To install OJS please follow the instructions at https://github.com/pkp/ojs/ . Check out a version that is compatible with the plugin - preferably an LTS release.

3. Make sure that you have the curl extension for PHP installed on the sevrer that is running OJS. On Ubuntu that can be done for example with:

```
sudo apt install php8.1-curl
```

4. Setup at least two journals on the OJS instance

This step is required to make the global settings show in the OJS menus.

5. Download plugin files

Download and copy the plugin files from github into plugins/generic/fidusWriter inside your OJS folder.
Create the folder if it does not exist. You can achieve this by running these commands:

```
cd plugins/generic/
git clone https://github.com/fiduswriter/ojs-fiduswriter.git fidusWriter
cd ../..
```

6. Register plugin with OJS by running:

```
php lib/pkp/tools/installPluginVersion.php plugins/generic/fidusWriter/version.xml
```

7. Activate plugin in OJS

Enable the plugin via the OJS website interface:

Open the OJS interface and select "ENABLE" under the settings "Fidus Writer Integration Plugin" under the following routes:

 setting > website > plugins


8. Configure the API key in OJS

Come up with an API Key to allow secure communications between Fidus Writer and OJS. This is just a single long text string that you should not share with anyone that will need to be entered in the configurations of Fidus Writer and OJS. Be cautious: The key allows automatic login into Fidus Writer and OJS in various ways, so do not share it!

To set the key in OJS, go to the settings of the Fidus Writer integration plugin under the following routes:

setting > website > plugins -> Fidus Writer Integration plugin (triangle to left) -> Settings -> Enter API key -> Save.


9. Activate connection on Fidus Writer side

Enter the administration interface at your Fidus Writer installation (http://myserver.com/admin).

In the section "Custom views" click on "Register journal". Enter the URL and API Key (from step 4) of your OJS installation.

## Install OJS on NGINX

If you need to install OJS on the same server as Fidus Writer, it may make sense to install both behind NGINX. A site cofniguration file an OJs instance could be initially be configured like this (before obtaining an SSL certificate using `certbot --nginx`):

```
server {
  listen 80;
  listen [::]:80;
  server_name OJS.DOMAIN.COM;
  root /var/www/ojs/;

  index index.php index.html index.htm;


  location / {
    try_files $uri $uri/ /index.php$uri?$args;
  }

  location ~ ^(.+\.php)(.*)$ {
    set $path_info $fastcgi_path_info;
    fastcgi_split_path_info ^(.+\.php)(.*)$;
    fastcgi_param PATH_INFO $path_info;
    fastcgi_param PATH_TRANSLATED $document_root$path_info;
    fastcgi_param SCRIPT_FILENAME $document_root/$fastcgi_script_name;
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
  }

  location ~ /\.ht {
    deny all;
  }
}

```



## Credits

This plugin was originally developed by the [Opening Scolarly Communications in the Social Sciences (OSCOSS)](http://www.gesis.org/?id=10714) project in 2017, financed by the German Research Foundation (DFG) and executed by the University of Bonn and GESIS – Leibniz Institute for the Social Sciences. In 2021-2022, development has been financed through the [Cluster of Excellence ROOTS](https://www.cluster-roots.uni-kiel.de/en) at Kiel University.

## License

This software is released under the the GNU General Public License v2.0.

See the file License.md included with this distribution for the terms of this license.

Third parties are welcome to modify and redistribute the plugin in entirety or parts according to the terms of this license. We also welcome patches for improvements or bug fixes to the software.
