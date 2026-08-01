# Geocoding — privacy model & upgrade path

The EXIF inspector turns a photo's GPS coordinate into a human place name
("Mount Helix, La Mesa, CA 91941"). This is the **only** part of the inspector
that *could* send data off your server — so it's built privacy-first.

## What ships today (zero data egress)

1. **Offline resolver (default, fully on-server).** A bundled San Diego County
   place list (`data/sd-county-places.tsv`, GeoNames-compatible) is searched for
   the nearest named place. No network call, nothing leaves the server. Returns
   neighborhood + city + state, and the ZIP **only when the coordinate is within
   ~4 km** of the place centroid (otherwise the ZIP is dropped — "show ZIP only
   when confident"). Beyond ~30 km from any known place it returns nothing and
   the UI shows raw coordinates only.

2. **User-initiated map link.** The panel shows an "Open in Maps" link. The
   user's browser contacts the map provider **only if they tap it** — the server
   never phones home.

3. **Resolve-once + cache.** The REST layer resolves lazily (only when a Details
   panel is first expanded) and caches the result into `meta_json.geo_place`,
   stamped with `provider` + `resolved_at`. A coordinate is never resolved twice,
   and a coordinate that resolves to nothing is cached as "tried, nothing" so it
   isn't retried.

### Coverage & overhead
- 119 places covering San Diego County cities + neighborhoods (La Mesa, El Cajon,
  Spring Valley, Mount Helix, Lakeside, the City of San Diego's neighborhoods,
  the back-country, etc.).
- The dataset is parsed into memory **only when a resolution actually happens**,
  and statically cached for the rest of that request. If nobody opens a Details
  panel, the file is never read → zero overhead in the common path.

## Expanding coverage (still offline, still zero egress)

Drop in a larger GeoNames file — no code changes, same parser, same format.
Download e.g. `US.txt` (or `cities5000.txt`) from GeoNames (CC-BY licensed),
keep the tab-separated columns, and point the dataset filter at it:

```php
add_filter( 'zdz_media_geocode_dataset_path', function () {
    return WP_CONTENT_DIR . '/uploads/geo/US.txt';
} );
```

> The bundled file uses a GeoNames-compatible column subset:
> `name  lat  lng  feature_code  admin1  country  zip  parent_city`
> The parser reads the first three (name, lat, lng) as required and the rest as
> optional, so a raw GeoNames dump works as-is (extra trailing columns ignored).

## Upgrading to STREET-LEVEL (self-hosted Nominatim)

When you stand up your own OpenStreetMap Nominatim instance (e.g. on the Mac
Studio, or any box you control), you get street-level accuracy **with no public
API and no third-party data sharing** — the coordinate goes only to *your* server.
Wire it through the single filter seam; the inspector code doesn't change:

```php
/**
 * Street-level reverse geocoding via a SELF-HOSTED Nominatim instance.
 * Replace NOMINATIM_BASE with your instance URL. Coordinates go only to your
 * own server. Result is cached by the inspector, so each photo hits Nominatim
 * at most once.
 */
add_filter( 'zdz_media_reverse_geocode', function ( $pre, $lat, $lng ) {
    if ( is_array( $pre ) ) {
        return $pre; // someone earlier already resolved it
    }

    $base = 'https://geo.internal.example.com'; // <-- your self-hosted Nominatim
    $url  = add_query_arg(
        [
            'format'          => 'jsonv2',
            'lat'             => $lat,
            'lon'             => $lng,
            'zoom'            => 18,          // building/street level
            'addressdetails'  => 1,
        ],
        $base . '/reverse'
    );

    $resp = wp_remote_get(
        $url,
        [
            'timeout' => 4,
            // Nominatim asks for an identifying UA / referrer.
            'headers' => [ 'User-Agent' => 'Zorderz/2.21 (self-hosted)' ],
        ]
    );
    if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
        return null; // fall through to the offline resolver
    }

    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    $a    = $data['address'] ?? [];
    if ( empty( $a ) ) {
        return null;
    }

    // Nominatim splits the locality across several keys depending on place type.
    $neighborhood = $a['neighbourhood'] ?? ( $a['suburb'] ?? ( $a['quarter'] ?? '' ) );
    $city         = $a['city'] ?? ( $a['town'] ?? ( $a['village'] ?? ( $a['hamlet'] ?? '' ) ) );
    $admin1       = $a['state'] ?? '';
    $postcode     = $a['postcode'] ?? '';
    $house        = trim( ( $a['house_number'] ?? '' ) . ' ' . ( $a['road'] ?? '' ) );

    // Prefer a street address line when available; else neighborhood/city.
    $primary = $house !== '' ? $house : trim( implode( ', ', array_filter( [ $neighborhood, $city ] ) ) );
    $label   = trim( implode( ', ', array_filter( [ $primary, trim( $admin1 . ' ' . $postcode ) ] ) ), ', ' );

    if ( '' === $label ) {
        return null;
    }

    return [
        'label'        => $label,
        'neighborhood' => $neighborhood,
        'city'         => $city,
        'admin1'       => $admin1,
        'postcode'     => $postcode,
        'country'      => strtoupper( $a['country_code'] ?? '' ),
        'provider'     => 'self-hosted-osm',
        'resolved_at'  => gmdate( 'Y-m-d H:i:s' ),
    ];
}, 10, 3 );
```

### Standing up Nominatim (outline)
- Use the official `mediagis/nominatim` Docker image.
- Import a region extract (e.g. `north-america-latest.osm.pbf` from Geofabrik) —
  for San Diego work, a `california-latest.osm.pbf` import is small and fast.
- A 96 GB machine handles a full North-America (or even planet) import; for a
  state extract it's very comfortable.
- For your **production website** to reach a Nominatim running on your Mac Studio,
  the instance must be reachable from where the site runs (reverse proxy / tunnel
  + TLS, and the box must be up when photos are resolved). For local
  experimentation, point the filter at `http://localhost:8080` while testing.

## A note on public APIs (why they're not the default)

- **Public OSM Nominatim** (`nominatim.openstreetmap.org`) is **off the table** as
  a built-in: its usage policy forbids being wired into low/no-code platforms as a
  generic geocoder and forbids systematic queries. Use a **self-hosted** instance
  instead (above).
- **BigDataCloud's free tier** requires the coordinate to be the *device's current
  location sent from the browser* — a mismatch for looking up stored photo
  coordinates, and it sends data to them. Not used.
- A **keyed commercial provider** (Google, etc.) can be wired through the same
  `zdz_media_reverse_geocode` filter if you accept sending coordinates to them.

## Turning the offline resolver off

It's on by default because it's fully local (no privacy cost) and lazy+cached
(no real overhead). To disable it (show only coords + map link until you wire a
resolver you trust):

```php
add_filter( 'zdz_media_offline_geocode_enabled', '__return_false' );
```
