# School directory

A searchable list of the **basic schools** (creche, KG, primary and JHS: the shop does not supply senior high schools) in the regions the shop supplies (**Greater Accra** and **Central**), used to add a school as a customer in one step instead of typing it in. Directory entries are not customers: an entry becomes linked to a customer when it is added (or linked to an existing customer).

## Source and licence

The directory is loaded from **OpenStreetMap** through the Overpass API: every feature tagged `amenity=school` or `amenity=kindergarten` inside each region's boundary, with its district (the `admin_level=6` boundary it lies in).

OpenStreetMap data is © OpenStreetMap contributors and available under the **Open Database License (ODbL)**. Using it inside the system is allowed; the attribution "School directory data © OpenStreetMap contributors, available under the Open Database License (ODbL)" is shown on the admin page, in the app's directory screen and in the API (`meta.attribution`). If the directory itself were ever published or shared as a database, it would have to be shared under the ODbL too. The downloaded files are kept in `laravel/storage/app/reference/` (git-ignored), like the NaCCA list.

Other "school list" websites were not scraped: their terms do not allow copying, and the government lists found online are either old (the 2012-13 schools census) or behind a login.

## Coverage (2026-10-06 download)

| Region | Basic schools listed | SHS, colleges, universities (skipped) | Without a name (skipped) | Districts |
|---|---:|---:|---:|---:|
| Greater Accra | 909 | 117 | 79 | 29 |
| Central | 275 | 89 | 31 | 22 |

**Only basic schools** (owner's decision): a school is skipped when its only level is SHS ("Senior High", "SHS", "Snr High", "Secondary School" but not "Junior Secondary", "High School" but not "Junior High", "International High", "Technical Institute", or OpenStreetMap's `isced:level=3`), or when it has no basic level and its name says college, university, polytechnic, institute, nursing, seminary or vocational. A school with any basic level ("JHS and SHS", "Preparatory College") is kept, and so is one whose level is unknown (most entries). A few private schools named "... College" or "... International High School" may teach basic levels too; add those by hand. Schools skipped by a later rule are withdrawn on the next import.

OpenStreetMap lists the schools volunteers have mapped, **not every school**: the two regions have several thousand basic schools, and private schools are the least mapped. Any school not in the directory is added as before with "New customer"; the customers CSV import (Phase 3.5.1) is the way to load a list from the district education offices, if the owner obtains one.

Fields: name, region, district (from the boundary, "District" dropped from the name, e.g. "Gomoa East"; municipal and metropolitan names kept), town (`addr:city`), phone (first number), levels (KG, Primary, JHS, SHS: from `amenity=kindergarten`, `isced:level`, or words in the name such as "KG", "Primary", "Basic", "JHS", "SHS"), ownership (public/private: from `operator:type`, or "D/A", "M/A", "L/A" in the name), position. Levels and ownership are hints, often empty.

## Using it

- **Admin:** Sales → School directory. Search by words of the name, district or town ("methodist cape coast", "kasoa"); filter by region, district, added or not. "Add as customer" creates the customer (type school, region, district, town as address, phone from the directory unless changed, contact person optional). "Link existing customer" links an entry to a customer typed in earlier.
- **App:** Customers → directory icon → search, tap a school, confirm. If a customer with the same name already exists the app offers to link it instead of adding a second one.
- **Rules:** one directory entry per customer and one customer per entry (database unique index); adding an entry that is already linked opens/returns its customer (`already_customer`); a same-name customer is never duplicated silently (`customer_name_exists`).

## Loading and refreshing

```
php artisan migrate
php artisan schools:import                     # both regions, downloads from OpenStreetMap
php artisan schools:import --region=GH-CP      # one region (GH-AA Greater Accra, GH-CP Central)
php artisan schools:import --region=GH-AA --file=storage/app/reference/osm-schools-GH-AA-....json
```

Re-running is safe: entries are matched by their OpenStreetMap id; changes update the entry; entries removed from OpenStreetMap are marked withdrawn (hidden from search, still linked to their customer) and come back if re-added; customers are never changed. A refresh every few months is enough. The public Overpass server is shared and sometimes busy (HTTP 504): the command retries, or run it later.

**HTTPS certificates on Windows:** if the download fails with `cURL error 60: SSL certificate ... unable to get local issuer certificate`, PHP has no CA certificate bundle. Download `cacert.pem` from https://curl.se/docs/caextract.html and set `curl.cainfo` and `openssl.cafile` to its path in `php.ini`. Production servers (Linux) have the bundle already. Until then, download the files another way and load them with `--file`.
