# 🧺 Silverstripe Forager > <img src="https://www.silverstripe.com/favicon.ico" style="height:40px; vertical-align:middle"/> Silverstripe Search

This module provides the ability to index content for a Silverstripe Search engine through the 🌈 Bifröst - the API for
Silverstripe's Search service.

This module **does not** provide any method for performing searches on your engines. See the [Searching](#searching)
section below for some suggestions.

## Installation

```shell
composer require silverstripe/silverstripe-forager-bifrost
```

## Activating the service

To integrate with Silverstripe Search, define environment variables containing your endpoint, engine prefix, and
management API key.

```
BIFROST_ENDPOINT="https://abc.provided.domain"
BIFROST_ENGINE_PREFIX="engine-name-excluding-variant"
BIFROST_MANAGEMENT_API_KEY="abc.123.xyz"
```

## Configuration

The most notable configuration surface is the schema, which determines how data is stored in your index. There are five
types of data supported:

* `text` (default)
* `date`
* `number`
* `geolocation`
* `binary` (only supported for the `_attachment` field - see below)

You can specify these data types in the `options` node of your fields.

```yaml
SilverStripe\Forager\Service\IndexConfiguration:
  indexes:
    myindex:
      includeClasses:
        SilverStripe\CMS\Model\SiteTree:
          fields:
            title: true
            summary_field:
              property: SummaryField
              options:
                type: text
```

### File attachments for content extraction

Firstly, you will need to set this environment variable. This will apply an extension to the `File` class, and allow
you to use the `_attachment` field (detailed below).

```yaml
SEARCH_INDEX_FILES=1
```

Silverstripe Search supports content extraction for many different file types. These can be attached to your Documents
using an `_attachment` field of type `binary`.

This field needs to contain a base 64 encoded string of binary for the file you wish to process.

```yaml
SilverStripe\Forager\Service\IndexConfiguration:
  indexes:
    myindex:
      includeClasses:
        SilverStripe\Assets\File:
          fields:
            title: true
            _attachment:
              property: getBase64String
              options:
                type: binary
```

Where `getBase64String` is a method in our `FileExtension` - which is applied to the `File` class by default as part
of this module.

## Additional documentation

Majority of documentation is provided by the Forager module. A couple in particular that might be useful to you are:

* [Configuration](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/configuration.md)
* [Customisation](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/customising.md)

## Searching

Silverstripe Search provides support for searching through its PHP SDK:

* [Discoverer > Bifröst](https://github.com/silverstripeltd/silverstripe-discoverer-bifrost)
* [Discoverer > Theme](https://github.com/silverstripeltd/silverstripe-discoverer-theme) (optional)
