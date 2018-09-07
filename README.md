# Multilang Tntsearch Plugin

**This README.md file should be modified to describe the features, installation, configuration, and general usage of this plugin.**

The **Multilang Tntsearch** Plugin is for [Grav CMS](http://github.com/getgrav/grav). fork of grav tntsearch allowing descreet language indexing

## Installation

Installing the Multilang Tntsearch plugin can be done in one of two ways. The GPM (Grav Package Manager) installation method enables you to quickly and easily install the plugin with a simple terminal command, while the manual method enables you to do so via a zip file.


### Manual Installation

To install this plugin, just download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then, rename the folder to `multilang-tntsearch`. You can find these files on [GitHub](https://github.com/gamahachaa/multilang-tntsearch) or via 

You should now have all the plugin files under

    /your/site/grav/user/plugins/multilang-tntsearch
	
> NOTE: This plugin is a modular component for Grav which requires [Grav](http://github.com/getgrav/grav) and the [Error](https://github.com/getgrav/grav-plugin-error) and [Problems](https://github.com/getgrav/grav-plugin-problems) to operate.

### Admin Plugin

## Configuration

Before configuring this plugin, you should copy the `user/plugins/multilang-tntsearch/multilang-tntsearch.yaml` to `user/config/plugins/multilang-tntsearch.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
search_route: /search
query_route: /s
built_in_css: false
built_in_js: false
built_in_search_page: true
search_type: basic
fuzzy: true
stemmer: default
display_route: false
display_hits: false
display_time: false
live_uri_update: false
limit: '50'
min: '3'
snippet: '300'
index_page_by_default: true
filter:
  items:
    taxonomy@:
      category:
        - doc
powered_by: false

```

Note that if you use the admin plugin, a file with your configuration, and named multilang-tntsearch.yaml will be saved in the `user/config/plugins/` folder once the configuration is saved in the admin.

## Usage

In cli use
```console
bin/plugin multilang-tntsearch indexLang __lang__ 
```
lang must be one in the system config
## Credits

**Did you incorporate third-party code? Want to thank somebody?**

## To Do

- [ ] Future plans, if any

