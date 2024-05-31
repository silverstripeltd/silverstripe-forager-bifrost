# silverstripeltd/search-service-bifrost

## Installation

Add the following to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:silverstripeltd/search-service-elasticsearch.git"
        }
    ]
}
```

Then run the following:

```shell
composer require silverstripeltd/search-service-bifrost
```

## Activating the service

To start using the Silverstripe Bifröst, define environment variables containing your endpoint, and engine prefix, and
private API key.

```
BIFROST_ENDPOINT="https://abc123.app-search.ap-southeast-2.aws.found.io:443"
BIFROST_ENGINE_PREFIX="index-name-excluding-variant"
BIFROST_PRIVATE_API_KEY="APIKEY123"
```

## Configuration

The most notable configuration surface is the schema, which determines how data is stored in your index. There are four
types of data supported:

* `text` (default)
* `date`
* `number`
* `geolocation`

You can specify these data types in the `options` node of your fields.

```yaml
SilverStripe\SearchService\Service\IndexConfiguration:
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

**Note**: Be careful about whimsically changing your schema. Your index will need to be fully reindexed if you change
the data type of a field.

## Additional documentation

Majority of documentation is provided by the Silverstripe Search Service module. A couple in particular that might be
useful to you are:

* [Configuration](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/configuration.md)
* [Customisation](https://github.com/silverstripe/silverstripe-search-service/blob/2/docs/en/customising.md)
