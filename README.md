# Souder Live Floor Plans

Standalone WordPress plugin for live, web-based floor plans powered by Souder CRE inventory data.

## What It Does

- Registers a `souder_floor_plan` admin post type.
- Lets staff upload a floor-plan image and assign a CRE building ID.
- Syncs suites from the existing Souder CRE platform.
- Lets staff place draggable suite labels over the floor-plan image.
- Renders tenant-facing floor plans with `[souder_floor_plan id="123"]`.
- Shows cached live availability, price, square footage, and suite links directly on the map labels.

## Data Source

The plugin uses a hybrid provider:

1. If `souder-cre-platform` is active and `CRE_Database` / `CRE_Domain` are available, it queries CRE custom tables directly.
2. Otherwise, it falls back to the public REST API at `/wp-json/cre/v1/suites`.

REST fallback paginates with `per_page=50` until all pages are loaded, so buildings with 250+ suites are supported.

## Public Shortcode

```text
[souder_floor_plan id="123"]
```

## Admin Workflow

1. Activate the plugin.
2. Go to **Live Floor Plans**.
3. Add a new floor plan.
4. Choose the CRE building from the building picker, or enter the building ID manually.
5. Select a floor-plan image from the media library.
6. Save the post.
7. Click **Sync suites now**.
8. Place suite labels by clicking **Place** in the suite list.
9. Drag labels into position.
10. Click **Save overlay positions**.
11. Embed the shortcode on a WordPress page.

## Cached Suite Shape

```json
{
  "id": 123,
  "building_id": 45,
  "suite_number": "D8",
  "floor": "1",
  "square_feet": 140,
  "monthly_rate": 750,
  "status": "available",
  "derived_status": "available",
  "available_date": null,
  "building_name": "1126 Sam Newell Road",
  "url": "https://example.com/suites/...",
  "updated_at": "2026-06-30T00:00:00Z"
}
```

## Notes

- Coordinates are stored as percentages relative to the floor-plan image.
- Public labels always show unit number and square footage. Available spaces also show price, with `Call for price` used when no price is set.
- Public pages read from this plugin's cached payload, not from the live CRE endpoint on every visitor request.
- If sync fails, the last successful cache remains available and the admin screen shows the sync error.
