# 🧺 Silverstripe Forager > <img src="https://www.silverstripe.com/favicon.ico" style="height:40px; vertical-align:middle"/> Silverstripe Search

This module provides the ability to index content for a Silverstripe Search engine through the 🌈 Bifröst - the API for Silverstripe's Search service.

This module **does not** provide any method for performing searches on your engines. See the [Searching](#searching) section below for some suggestions.

<!-- TOC -->
* [🧺 Silverstripe Forager > <img src="https://www.silverstripe.com/favicon.ico" style="height:40px; vertical-align:middle"/> Silverstripe Search](#-silverstripe-forager--img-srchttpswwwsilverstripecomfaviconico-styleheight40px-vertical-alignmiddle-silverstripe-search)
  * [Installation](#installation)
  * [Engine vs Index](#engine-vs-index)
  * [Specify environment variables](#specify-environment-variables)
    * [Understanding your engine prefix and engine suffix:](#understanding-your-engine-prefix-and-engine-suffix)
  * [Configuration](#configuration)
    * [File attachments for content extraction](#file-attachments-for-content-extraction)
  * [Additional documentation](#additional-documentation)
  * [Searching](#searching)
<!-- TOC -->

## Installation

```shell
composer require silverstripe/silverstripe-forager-bifrost
```

## Engine vs Index

> [!IMPORTANT]
> **TL;DR:**\
> For all intents and purposes, "engine" and "index" are synonomous. If we refer to something as "engine", but the Discoverer module is asking for an "index", then you simply need to give it the data you have for your engine.

The Discoverer module is built to be service agnostic; meaning, you can use it with any search provider, as long as there is an adaptor (like this module) for that service.

When Discoverer refers to an "index", it is talking about the data store used for housing your content. These data stores are known by different names across different search providers. Algolia and Elasticsearch call them "indexes", Typesense calls them "collections", App Search calls them "engines". Discoverer had to call them **something** in its code, and it chose to call then "indexes"; Silverstripe Search, however, calls them "engines".

Actions apply in the same way to all of the above. In Silverstripe Search, the action of "indexing" is the action of adding data to your engine, where it is said to be "indexed". Updating that data is commonly referred to as "re-indexing".

## Specify environment variables

To integrate with Silverstripe Search, define environment variables containing your endpoint, engine prefix, and management API key.

```
BIFROST_ENDPOINT="https://abc.provided.domain"
BIFROST_ENGINE_PREFIX="<enginePrefix>" # See "Understanding your engine prefix and engine suffix" below
BIFROST_MANAGEMENT_API_KEY="abc.123.xyz"
```

### Understanding your engine prefix and engine suffix:

> [!IMPORTANT]
> **TL;DR:**
> - All Silverstripe Search engine names follow a 4 slug format like this: `search-<subscription>-<environment>-<engineSuffix>`
> - Your `<enginePrefix>` is everything except `-<engineSuffix>`; so, it's just `search-<subscription>-<environment>`

For example:

| Engine name               | Engine prefix        | Engine suffix |
|---------------------------|----------------------|---------------|
| search-acmecorp-prod-main | search-acmecorp-prod | main          |
| search-acmecorp-prod-inc  | search-acmecorp-prod | inc           |
| search-acmecorp-uat-main  | search-acmecorp-uat  | main          |
| search-acmecorp-uat-inc   | search-acmecorp-uat  | inc           |

**Why?**

Because you probably have more than one environment type that you're running search on (e.g. Production and UAT), and (generally speaking) you should have different engines for each of those environments. So, you can't just hardcode the entire engine name into your project, because that code doesn't change between environments.

Whenever you make a query, Forager will ask you for the "index" name; you will actually want to provide only the `<engineSuffix>`. We will then take `BIFROST_ENGINE_PREFIX` and your `<engineSuffix>`, put them together, and that's what will be queried. This allows you to set `BIFROST_ENGINE_PREFIX` differently for each environment, while having your `<engineSuffix>` hardcoded in your project.

## Configuration

> [!WARNING]
> Once you add a field to an index you cannot change its name or type without deleting the engine so choose field names and set their types carefully

The most notable configuration surface is the schema, which determines how data is stored in your index. There are five types of data supported:

* `text` (default)
* `date`
* `number`
* `geolocation`
* `binary` (only supported for the `_attachment` field - see below)

You can specify these data types in the `options` node of your fields.

```yaml
SilverStripe\Forager\Service\IndexConfiguration:
  indexes:
    <engineSuffix>:
      includeClasses:
        SilverStripe\CMS\Model\SiteTree:
          fields:
            title: true
            summary_field:
              property: SummaryField
              options:
                type: text
```

Continuing with the `acmecorp` engine examples; they have 2 engines per environment, so it would look something like this:

```yaml
SilverStripe\Forager\Service\IndexConfiguration:
  indexes:
    main:
      includeClasses:
        ...
    inc:
      includeClasses:
        ...
```

### File attachments for content extraction

Firstly, you will need to set this environment variable. This will apply an extension to the `File` class, and allow you to use the `_attachment` field (detailed below).

```yaml
SEARCH_INDEX_FILES=1
```

Silverstripe Search supports content extraction for many different file types. These can be attached to your Documents using an `_attachment` field of type `binary`.

This field needs to contain a base 64 encoded string of binary for the file you wish to process. You should also define the special `body` field with type `text` which will automatically be created to store the extracted file content.

```yaml
SilverStripe\Forager\Service\IndexConfiguration:
  indexes:
    <engineSuffix>:
      includeClasses:
        SilverStripe\Assets\File:
          fields:
            title: true
            body: true # this is the field that will contain the extract content
            _attachment:
              property: getBase64String
              options:
                type: binary
```

Where `getBase64String` is a method in our `FileExtension` - which is applied to the `File` class by default as part of this module.

## Additional documentation

Majority of documentation is provided by the Forager module. A couple in particular that might be useful to you are:

* [Configuration](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/configuration.md)
* [Customisation](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/customising.md)

## Searching

Silverstripe Search provides support for searching through its PHP SDK:

* [Discoverer > Bifröst](https://github.com/silverstripeltd/silverstripe-discoverer-bifrost)
* [Discoverer > Theme](https://github.com/silverstripeltd/silverstripe-discoverer-theme) (optional)
