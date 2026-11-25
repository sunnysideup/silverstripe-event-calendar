# Silverstripe Event Calendar

## Introduction

This is an event calendar module for Silverstripe CMS, with the following features:

* **Calendar** - A page type used to hold/present events and announcements.
* **Calendar event** - A page type which represents an event, with one or more DateTimes (an instance of an event).
* **Recurring events** - Calendar events can be set up reoccur automatically.
* **Calendar announcements** - Entries in a calendar which don't have an event page associated.
* **ICS feeds** - Add external ICS feeds to a calendar to display these events.
* **ICS output** - Download an ics file for easy importing into calendar apps.
* **RSS feed** - RSS feed of calendar events.
* **Calendar widget** - Display a calendar view in a widget, so website users can select to view events by year/month/week/day periods.
* **Caching**

## Requirements

* PHP 8.3 or greater
* Silverstripe CMS 6
* [Carbon](https://github.com/briannesbitt/carbon) 3
* [ICS Parser](https://github.com/u01jmg3/ics-parser) 3 (for external ICS feeds)

## Configuration Options

Enable jQuery (that is, do not request a local copy)

```yaml
UncleCheese\EventCalendar\Pages\Calendar:
    jquery_included: true
```

Caching options. When caching is enabled, events are read from a table that is filled by the
`cache-event-calendars` build task (`sake tasks:cache-event-calendars`), so schedule that task (e.g. hourly).

```yaml
UncleCheese\EventCalendar\Pages\Calendar:
    caching_enabled: true
    cache_future_years: 2
```

Set default time zone and language for ICS output

```yaml
UncleCheese\EventCalendar\Pages\Calendar:
    timezone: America/New_York
    language: EN
```

Disable automatic link to RSS appearing

```yaml
UncleCheese\EventCalendar\Pages\Calendar:
    include_rss_link: false
```

Limit how far recurring events are expanded in one request (days)

```yaml
UncleCheese\EventCalendar\Pages\Calendar:
    max_date_range_days: 1100
    max_recurrence_lookahead_days: 3650
```

## ICS feeds

External ICS feeds are downloaded on the server. For safety, only `http(s)` (and `webcal`) URLs that point to
public hosts are accepted, the download is limited in time and size, and the result is cached
(in the `EventCalendarFeeds` cache). You can tweak this:

```yaml
UncleCheese\EventCalendar\Helpers\ICSFeedReader:
    cache_lifetime: 3600
    timeout: 5
    max_bytes: 5242880
    # only enable this if you trust everyone who can edit feeds, e.g. for an intranet
    allow_private_hosts: false
```

## Upgrading to Silverstripe 6

* Templates use the `<%t Class.KEY 'Default' %>` translation syntax (`<% _t() %>` no longer exists).
* `ICSWriter::sendDownload()` now returns an `HTTPResponse` - return it from your controller action.
* The old `CachedCalendarTask` / `CachedCalendarBuildTask` have been replaced by a new `CachedCalendarBuildTask`
  (`sake tasks:cache-event-calendars`). The `cache_future_years` setting now lives on `Calendar`.
* The unused `iCal` helper and the legacy live calendar widget (templates, JS, CSS and jQuery 1.2.6) have been removed.
* `Calendar::getEventList()` filters are added to the query as-is. Use parameterised filters
  (`['"SiteTree"."Title" LIKE ?' => ['%' . $term . '%']]`) when they contain user input.
* Run `dev/build` after upgrading (`CachedCalendarEntry.ICSLink` is now longer and the many_many relations are declared explicitly).
