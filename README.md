# 🧺 Silverstripe Forager > <img src="https://www.silverstripe.com/favicon.ico" style="height:40px; vertical-align:middle"/> Silverstripe Search Provider

This module provides the ability to index content for a Silverstripe Search engine.

Silverstripe Search provider for [Silverstripe Forager](https://github.com/silverstripeltd/silverstripe-forager).

This module **does not** provide any method for performing searches on your engines. See the [Searching](#searching)
section below for some suggestions.

## Installation

```shell
composer require silverstripe/silverstripe-forager-bifrost
```

## Activating the service

To start using the Silverstripe Bifröst, define environment variables containing your endpoint, and engine prefix, and
private API key.

```
BIFROST_ENDPOINT="https://abc123.app-search.ap-southeast-2.aws.found.io:443"
BIFROST_ENGINE_PREFIX="index-name-excluding-variant"
BIFROST_MANAGEMENT_API_KEY="APIKEY123"
```

## Configuration

The most notable configuration surface is the schema, which determines how data is stored in your index. There are four
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

The Silverstripe Search Service supports content extraction for PDF and Docx files. These can be attached to your
Document using an `_attachment` field of type `binary`.

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

Where `getBase64String` is a method in our `FileExtension` - which is applied to the `File` class by default.

## Additional documentation

Majority of documentation is provided by the Silverstripe Search Service module. A couple in particular that might be
useful to you are:

* [Configuration](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/configuration.md)
* [Customisation](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/customising.md)

## Searching

The Silverstripe Search service provides support for searching through its PHP SDK:

* [Discoverer > Bifröst](https://github.com/silverstripeltd/silverstripe-discoverer-bifrost)
* [Discoverer > Theme](https://github.com/silverstripeltd/silverstripe-discoverer-theme) (optional)
