# Hash Change Detection System - Process Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                            USER ACTION                                          │
│                Customer::find(123)->update(['name' => 'ACME Corp Ltd'])        │
└─────────────────────────┬───────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                       ELOQUENT EVENTS                                          │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐            │
│  │   Model::save() │ -> │   updated event │ -> │ InteractsWithHashes          │
│  └─────────────────┘    └─────────────────┘    │   ::updated()    │            │
│                                                 └─────────────────┘            │
└─────────────────────────┬───────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                      HASH CALCULATION                                          │
│                                                                                 │
│  1. Attribute Hash:                                                             │
│     ┌─────────────────────────────────────────────────────────────────┐       │
│     │ MD5("ACME Corp Ltd|customer@example.com|active")                 │       │
│     │ = "a1b2c3d4e5f6g7h8..."                                           │       │
│     └─────────────────────────────────────────────────────────────────┘       │
│                                                                                 │
│  2. Composite Hash (includes related models):                                  │
│     ┌─────────────────────────────────────────────────────────────────┐       │
│     │ MD5(customer_hash + orders_hash + addresses_hash)                │       │
│     │ = "x1y2z3w4v5u6t7s8..."                                           │       │
│     └─────────────────────────────────────────────────────────────────┘       │
└─────────────────────────┬───────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                     CHANGE DETECTION                                           │
│                                                                                 │
│  ┌───────────────────┐    ┌─────────────────┐    ┌─────────────────────┐       │
│  │ Current Hash:     │    │ Previous Hash:  │    │ Changed?            │       │
│  │ a1b2c3d4e5f6g7h8  │ vs │ z9y8x7w6v5u4t3  │ -> │ YES - Hashes differ │       │
│  └───────────────────┘    └─────────────────┘    └─────────────────────┘       │
│                                                                                 │
└─────────────────────────┬───────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                      HASH STORAGE                                              │
│                                                                                 │
│  UPDATE hashes SET                                                              │
│    current_hash = 'a1b2c3d4e5f6g7h8...',                                       │
│    composite_hash = 'x1y2z3w4v5u6t7s8...',                                     │
│    published_hash = NULL,  -- Mark as unpublished                              │
│    updated_at = NOW()                                                           │
│  WHERE hashable_type = 'customer' AND hashable_id = 123                        │
│                                                                                 │
└─────────────────────────┬───────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                    DEPENDENCY UPDATES                                          │
│                                                                                 │
│  Customer changed -> Update dependent models:                                   │
│                                                                                 │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐            │
│  │     Orders      │    │    Invoices     │    │   Addresses     │            │
│  │ (depends on     │    │ (depends on     │    │ (depends on     │            │
│  │  customer)      │    │  customer)      │    │  customer)      │            │
│  └─────────────────┘    └─────────────────┘    └─────────────────┘            │
│          │                       │                       │                    │
│          ▼                       ▼                       ▼                    │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐            │
│  │ Recalculate     │    │ Recalculate     │    │ Recalculate     │            │
│  │ composite hash  │    │ composite hash  │    │ composite hash  │            │
│  └─────────────────┘    └─────────────────┘    └─────────────────┘            │
│                                                                                 │
└─────────────────────────┬───────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                      PUBLISHING SYSTEM                                         │
│                                                                                 │
│  Models marked as "unpublished" (published_hash = NULL)                        │
│                                                                                 │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │                       DetectChangesCommand                               │   │
│  │                                                                         │   │
│  │  1. Find models where published_hash != current_hash                    │   │
│  │  2. Group by publisher type (api, webhook, file, etc.)                 │   │
│  │  3. Call appropriate publisher for each changed model                  │   │
│  │  4. Update published_hash = current_hash on success                    │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                                                                                 │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐            │
│  │   API Publisher │    │ Webhook Publisher│   │  File Publisher │            │
│  │ POST /sync      │    │ POST webhook_url │   │ Export to CSV   │            │
│  └─────────────────┘    └─────────────────┘    └─────────────────┘            │
│                                                                                 │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## Key Components

### 1. Hash Calculators
- **MySQLHashCalculator**: Fast database-level hash calculation
- **DependencyHashCalculator**: Handles composite hashes with related models
- **CompositeHashCalculator**: Combines multiple hash types

### 2. Change Detection Services
- **ChangeDetector**: Compares current vs stored hashes
- **HashUpdater**: Updates hash records in database

### 3. Model Integration
- **InteractsWithHashes**: Trait that hooks into Eloquent events
- **Model Observers**: Automatic hash updates on model changes

### 4. Publishing System
- **DetectChangesCommand**: Finds and publishes changed models
- **Publishers**: Various output formats (API, webhooks, files)
- **Publish Records**: Track what was published when

## Database Tables

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│     hashes      │    │ hash_dependents │    │    publishes    │
├─────────────────┤    ├─────────────────┤    ├─────────────────┤
│ id              │    │ id              │    │ id              │
│ hashable_type   │    │ hash_id         │    │ hash_id         │
│ hashable_id     │    │ dependent_type  │    │ publisher_type  │
│ current_hash    │    │ dependent_id    │    │ published_hash  │
│ composite_hash  │    │ created_at      │    │ published_at    │
│ published_hash  │    │ updated_at      │    │ created_at      │
│ created_at      │    │ deleted_at      │    │ updated_at      │
│ updated_at      │    └─────────────────┘    │ deleted_at      │
│ deleted_at      │                           └─────────────────┘
└─────────────────┘
```

## Performance Features

- **Bulk Processing**: BulkHashProcessor for large datasets
- **MySQL Optimization**: Database-level hash calculations
- **Cross-Database**: Hash tables can be in different database
- **Efficient Queries**: Optimized for large-scale operations