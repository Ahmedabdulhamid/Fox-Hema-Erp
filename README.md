<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).


## Modules and Permissions Management

The implementation lives in `app/Modules/AccessManagement` and reuses Spatie Permission 8,
Filament 5, the `web` guard, existing policies, and location-scoped role/direct-permission
assignments. There is no Super Admin Gate bypass. A deny-only Gate callback runs before
Spatie's permission callback so a mapped inactive module cannot authorize an ability.

### Registration and data

The registry creates exactly these eleven module codes:
`access_management`, `products`, `inventory`, `inventory_costing`, `purchasing`, `taxes`,
`discounts`, `sales`, `pos`, `stock_transfers`, and `payments`.
AccessManagement is enabled. The other ten start disabled and their descriptions identify
business operations and pages as planned. No Dashboard, Accounting, Reports, Settings,
or Cash & Treasury module is added.

`modules` stores a unique code, name, optional description/icon, display order, active flag,
and timestamps. `modules_permissions` stores an ID, foreign keys to `modules` and existing
`permissions`, timestamps, and a unique `(module_id, permission_id)` pair. The mapping
supplements the five existing Spatie tables; it does not grant permissions by itself.

All seventeen existing permission identifiers are preserved. Only `modules.view` and
`modules.update` are added. Existing access permissions map to AccessManagement. Existing
business permissions, if introduced by a real implementation, map by their exact module
prefix when registration is rerun. Historical unmapped permissions remain visible in a
separate group. Underscores in prefixes are treated literally.

The seeder retains module settings, existing users, roles, permission records, mappings,
and historical grants. It adds all existing `web` permissions to the global `super_admin`
role without detaching grants. It also preserves the existing idempotent registration of
location-specific `cashier` and `stock_manager` roles. It does not create, activate, restore,
or promote users. Old `SEED_ADMIN_*` configuration is no longer used.

### Filament pages and authorization

- `/admin/modules`: registered modules, activation, display order, permission counts.
- `/admin/modules/{record}`: details and associated permission names.
- `/admin/modules/{record}/edit`: activation and order; no arbitrary module creation/deletion.
- `/admin/permissions`: read-only permission list grouped by module.
- `/admin/permissions/{record}`: permission key, guard, and module.

Existing Users and Roles resources use the module mapping for permission selections.
Arbitrary permission creation from the User form has been removed. Permission records are
read-only even for Super Admins. Module listing requires `modules.view`; editing also
requires `modules.update`. Permission listing requires `permissions.view`.

Inactive business modules deny access for everyone, including Super Admins. Enabling a
planned module does not create pages or business functionality. AccessManagement cannot
be disabled by the UI or normal Module model updates, and its code cannot be changed.

For future implemented business routes, apply `module.active:<code>` alongside authentication,
location context, and the relevant policy. Policies and business services should use
`ModuleAccess::authorize($user, $code, $permission)` before operations. This checks module
activation, the permission-to-module mapping, and authorization; record location checks
remain the responsibility of the corresponding policy/service. No business routes have
been added in this change.

Persistent Livewire middleware checks account security and restores location context.
Context cleanup wraps the entire Filament/web request, so it occurs after component
mutations rather than before them. Actual HTTP Livewire updates have regression coverage.

Role mutations validate submitted scope, guard, existing permission IDs, delegation
ownership, and assignment authority. Assigned roles cannot change scope; create another
role instead. Changed user access requires `permissions.assign`, ordinary users cannot
delegate permissions they do not hold, self-escalation is blocked, and Super Admin role
assignment is reserved for explicit CLI provisioning. User deletion now requires
`users.delete` rather than `users.update`.

The last active global `web` Super Admin is protected against disabling, deletion, role
synchronization, and role removal. Managed mutations use transactions and serialize on
the AccessManagement module row to coordinate global assignments and administrator
protection on MySQL. Raw SQL/query-builder writes bypass Eloquent and application safeguards
and must remain trusted administrative operations.

### Installation and administrator provisioning

Run from the Laravel application root after reviewing the target environment:

```powershell
php artisan migrate --path=app/Modules/AccessManagement/Database/Migrations
php artisan db:seed --class="App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder"
```

Create a new administrator with an explicit target email:

```powershell
php artisan access:provision-super-admin admin@example.com
```

The command prompts for a name, hidden password, and password confirmation. It validates
the email and requires at least twelve password characters. New users must change their
password at first login. There is no password command-line option and no HTTP endpoint.
Non-interactive creation is refused.

Assign the role to an existing active user, preserving their password and account fields:

```powershell
php artisan access:provision-super-admin existing@example.com --assign-existing
```

Rerunning explicit assignment is safe. An existing user is never promoted without
`--assign-existing`; disabled/deleted users require separate review and are not restored.
The command requires registration/seeding first and restores the original location
permission context after provisioning. Passwords are never printed or included in audits.

### Changed files

New files inside `app/Modules/AccessManagement`:

```text
Console/Commands/ProvisionSuperAdmin.php
Database/Factories/ModuleFactory.php
Database/Migrations/2026_10_11_140808_create_modules_and_modules_permissions_tables.php
Models/Module.php
Policies/ModulePolicy.php
Policies/PermissionPolicy.php
Services/ModuleAccess.php
Services/ModuleRegistry.php
Services/ModuleRegistryLock.php
Services/PermissionGroups.php
Services/RoleAccessManager.php
Services/SuperAdminProtection.php
Services/SuperAdminProvisioner.php
Http/Middleware/EnsureModuleActive.php
Http/Middleware/ResetLocationPermissionContext.php
Filament/Resources/Modules/ModuleResource.php
Filament/Resources/Modules/Pages/ListModules.php
Filament/Resources/Modules/Pages/ViewModule.php
Filament/Resources/Modules/Pages/EditModule.php
Filament/Resources/Permissions/PermissionResource.php
Filament/Resources/Permissions/Pages/ListPermissions.php
Filament/Resources/Permissions/Pages/ViewPermission.php
```

Modified files inside `app/Modules/AccessManagement`:

```text
Database/Seeders/AccessManagementSeeder.php
Models/User.php
Policies/UserPolicy.php
Providers/AccessManagementServiceProvider.php
Services/LocationContext.php
Services/UserAccessManager.php
Http/Middleware/SetLocationPermissionContext.php
Filament/Resources/Roles/Pages/CreateRole.php
Filament/Resources/Roles/Pages/EditRole.php
Filament/Resources/Roles/Schemas/RoleForm.php
Filament/Resources/Users/UserResource.php
Filament/Resources/Users/Pages/EditUser.php
```

Other modified files:

```text
.env.example
README.md
bootstrap/app.php
bootstrap/providers.php
config/access-management.php
app/Providers/Filament/AdminPanelProvider.php
app/Modules/Products/Providers/ProductManagementServiceProvider.php
```

New test: `tests/Feature/ModulesPermissionsTest.php`.
Modified tests/helpers:

```text
tests/TestCase.php
tests/Feature/AccessManagement/AccessManagementTest.php
tests/Feature/AccessScopeUiTest.php
tests/Feature/LocalizationTest.php
tests/Feature/UserCreateOptionsTest.php
tests/Feature/UserLocationRelationManagersTest.php
tests/Feature/UserSoftDeletesTest.php
```

Existing tests explicitly provision their test administrator now that seeding does not
provision users. Expectations for arbitrary permission creation were replaced with the
read-only behavior. No dependency, business model, or existing business migration changes
were required. The Products provider namespace and registration now match its module path.

### Separate business migration issues

Business migrations were neither modified nor applied to the configured database:

- Only AccessManagement migrations are loaded by a service provider. Several business tests
  add their own migration paths.
- Purchasing timestamps precede prerequisite access/product/inventory tables. Payments
  precedes POS prerequisites on the same date. Loading all directories together requires
  an explicit dependency-order strategy.
- Tax migrations reside in Discounts and discount migrations reside in Taxes.
- Several business migrations use raw MySQL-style `ALTER TABLE ... ADD CONSTRAINT`, which
  does not work with the SQLite test configuration.
- Existing nullable location composite unique indexes permit duplicate global rows at the
  database level. Managed registration/provisioning uses a transaction lock and existence
  checks; historical duplicates are not automatically deleted or merged.

Business-module implementation, MySQL integration/concurrency validation, and resolution
of those migration issues remain separate work. No deployment, Git push, production-data
mutation, destructive database command, or business migration run was performed.


### Verification results

The relevant PHPUnit suite passed: **76 tests, 625 assertions** using SQLite `:memory:`.
It covers registration/mapping idempotency, historical data preservation, future permission
synchronization, guard/location isolation, direct URLs and navigation, read-only permissions,
module activation, explicit provisioning, password confirmation, delegation and scope
escalation, last-active-Super-Admin protection, and a real HTTP Livewire mutation.

```powershell
php vendor/bin/phpunit tests/Feature/ModulesPermissionsTest.php tests/Feature/AccessManagement/AccessManagementTest.php tests/Feature/UserCreateOptionsTest.php tests/Feature/AccessScopeUiTest.php tests/Feature/UserSoftDeletesTest.php tests/Feature/UserLocationRelationManagersTest.php tests/Feature/LocalizationTest.php --no-coverage
```

Pint formatting completed successfully for the changed PHP files. The required
`php vendor/bin/pint --dirty --format agent` attempt failed because this workspace has no
Git repository; explicit changed-file paths were supplied to Pint instead. Initial test
failures uncovered a joined permission-route binding issue, Gate callback order, and
location-context lifecycle problems; those were fixed before the successful final run.
The broader business suite and MySQL integration/concurrency tests were not run.


## Phase 3: Products management

Products reuses the existing seven catalog tables. ProductVariant is the sellable,
stock-tracked entity; catalog prices are shared across locations. No location-specific
prices, checkout, stock mutation, valuation engine, or tax/discount calculation was added.
New Eloquent products and Products UI/service creations default to Draft/inactive.
The old migration defaults were preserved; trusted direct SQL can still bypass model defaults.
Activation requires a valid active variant; disabling/deleting the last active variant
of an active product is rejected. Zero sale prices remain valid catalog values.

### Access and interfaces

The admin panel discovers five Resources: Products, Brands, Categories, Sizes, Colors.
Each has list/create/view/edit pages, separate form/infolist/table classes, filters and
service-backed mutations. ProductMutation maps service validation errors onto form fields
and shows deletion failures as notifications. Variants and Images are Product relation managers. Assignment
actions on product view/edit pages use the existing product-level tax/discount pivots
and appear only when the corresponding module and required tables are available.
Definitions are not edited here and transaction amounts are never recalculated.

All Products operations use registered web-guard permissions, ProductsAccess, model
policies, and the existing ModuleAccess and LocationContext. Module deactivation blocks
Super Admins as well as ordinary users, including subsequent Livewire requests.
Products-only users can enter the panel. Permission grouping and additive Super Admin
synchronization reuse the existing ModuleRegistry; module activation/name/order and
historical grants are preserved. No catalog permissions are automatically granted to
existing cashier/stock-manager roles. The catalog itself is shared: a location-scoped
catalog grant authorizes changes affecting every location.

Sale-price updates need their own permission in addition to variant create/update.
Reference-cost reads and writes are independently guarded; writes also require visibility.
Reference costs are excluded from unauthorized relation-table queries and edit action
state. They never update InventoryCosting average or standard accounting costs.

Following explicit user instruction during Phase 3, new Super Admin provisioning again
sets must_change_password=true. Existing-user provisioning preserves credentials and
flags. The password-change middleware regression test explicitly sets the flag it tests.

### Business integrity and deletion

Simple products have one dimensionless variant. Variable products use distinct size/color
combinations; base and dimensional variants cannot mix. Variant SKU and barcode are
unique across all variants, including inactive records; blank barcode becomes NULL and
leading zeros are preserved. Product default_sku is an optional, independently unique
catalog identifier. No SKU/barcode auto-generation or currency assumption was added.
Amounts accept non-negative decimals fitting 14 integer and four fractional digits.
Category cycles, invalid color codes, and newly assigned inactive lookup values are rejected.
Existing inactive assignments are preserved when unrelated fields change.

Variant combinations, category changes, lookup deletion and primary-image changes use
transactions and the existing ModuleRegistryLock (the AccessManagement module row).
This deliberately serializes managed catalog mutations on MySQL. The existing nullable
variant composite unique index remains unchanged: direct SQL/Eloquent writes outside these
services can create duplicate NULL combinations. SQLite does not prove MySQL row-lock or
collation behavior; separate MySQL concurrency verification is still required.

SKU/barcode/dimensions cannot change once a variant has any inventory, costing,
purchasing, sales, return or stock-transfer reference. Strict string comparisons preserve
barcode identity such as 001 versus 1. Category reassignment is also blocked for referenced
products. Deletion does not depend on dependency-module activation or nonzero stock:
zero balances and historical records still block deletion. Unused variants may be deleted;
products may be deleted only when Draft and without variants. Referenced lookup values,
category children and valuation settings block lookup deletion. Historical business rows
are never cascaded/deleted by these services; archive/deactivate is the alternative.
Reference tests include minimal transaction-reference fixtures to avoid changing unrelated
business migration ordering/SQLite constraints, plus actual Inventory/Costing schema tests.

### Images and additive migration

The only new migration is
app/Modules/Products/Database/Migrations/2026_10_11_160705_create_product_image_cleanup_tasks_table.php.
It adds a durable cleanup outbox (disk/path, retry count, ready_at, timestamps), not a redesign
of catalog tables. No existing migration was edited. The Products provider loads its own
migration directory only; configured-database migrations and seeders were not run.

Images accept JPEG/PNG/WebP content up to 5 MB, use server-generated UUID names and
MIME-derived extensions under public/products/{product_id}, and reject client-controlled
disk/path values. Optional variants must belong to the image's product. One primary image
is allowed per product-level group and per variant group. Public images are public assets;
module permissions govern management, not previously issued public image URLs.

New uploads receive cleanup markers before storage; abandoned markers become eligible
after one hour. Committed replacements/deletions attempt cleanup immediately and retain
retryable tasks after storage failures. Existing shared paths remain until unreferenced.
Legacy paths outside products/ and non-public disks are retained for manual review rather
than deleting arbitrary files. Pending task IDs are logged without sensitive values.

Command: php artisan products:cleanup-images --limit=100
(limit 1..10000). It only processes due, unreferenced cleanup tasks and returns failure if
any attempted cleanup remains unsuccessful. Run it periodically in the existing scheduler
or operational maintenance after applying the additive migration; no schedule was installed
or cleanup command run against the configured database in this work. Upload operations
must not be wrapped in an external long-lived database transaction: their pre-storage marker
must commit before filesystem writes to survive process termination.

### Registered Products permissions (31)

- `products.view`
- `products.create`
- `products.update`
- `products.delete`
- `products.brands.view`
- `products.brands.create`
- `products.brands.update`
- `products.brands.delete`
- `products.categories.view`
- `products.categories.create`
- `products.categories.update`
- `products.categories.delete`
- `products.sizes.view`
- `products.sizes.create`
- `products.sizes.update`
- `products.sizes.delete`
- `products.colors.view`
- `products.colors.create`
- `products.colors.update`
- `products.colors.delete`
- `products.variants.create`
- `products.variants.update`
- `products.variants.delete`
- `products.images.create`
- `products.images.update`
- `products.images.delete`
- `products.update_prices`
- `products.view_reference_cost`
- `products.update_reference_cost`
- `products.assign_taxes`
- `products.assign_discounts`

### Verification and remaining work

Final focused Products tests passed: 39 tests, 272 assertions.
Products/AccessManagement combined regression run passed: 108 tests, 777 assertions.
The final image-ownership guard was then verified by rerunning all 39 Products tests.
Dependency checks passed separately: InventoryCostingModelsTest (9 tests, 59 assertions),
and TaxesAndDiscountsModelsTest plus the Sales/Purchasing/StockTransfer/POS unit model
checks (24 tests, 495 assertions). An earlier combined dependency run produced nine
missing-table errors in InventoryCosting: existing RefreshDatabase classes register
incompatible migration paths while sharing the cached SQLite in-memory database.
Running the two dependency groups in separate processes passed. This suite-order issue
remains documented; no unrelated business migration was changed.
Pint --dirty could not run because this directory has no Git repository. Formatting was
completed with explicit changed PHP paths, excluding all seven original Products migrations.
Tests use SQLite :memory:, never the configured application database. Missing dependency
tables, inactive modules, location isolation, sensitive mutations, direct URLs, relation
manager uploads/actions, permission revocation, cleanup failures and historical references
are covered. Existing AccessManagement tests were updated for the implemented Products
permissions, retaining the historical-grant and scope assertions.

Unrelated business migration ordering, swapped Taxes/Discounts migration folders and
MySQL-specific ALTER TABLE constraints remain separate work (see Phase 2 above).
Raw writes can bypass service integrity checks; no new global ORM authorization system was
introduced. Existing data is not silently repaired, merged, renamed or removed. Storage
cleanup retries require the command to be run after operational failures. No deployment,
Git push, configured-database mutation, or destructive database command was performed.

### Complete changed-file inventory

New source/test/migration files (69):

```text
app/Modules/Products/Console/Commands/CleanupProductImages.php
app/Modules/Products/Database/Factories/BrandFactory.php
app/Modules/Products/Database/Factories/CategoryFactory.php
app/Modules/Products/Database/Factories/ColorFactory.php
app/Modules/Products/Database/Factories/ProductFactory.php
app/Modules/Products/Database/Factories/ProductImageFactory.php
app/Modules/Products/Database/Factories/ProductVariantFactory.php
app/Modules/Products/Database/Factories/SizeFactory.php
app/Modules/Products/Database/Migrations/2026_10_11_160705_create_product_image_cleanup_tasks_table.php
app/Modules/Products/Filament/ProductMutation.php
app/Modules/Products/Filament/Resources/Brands/BrandResource.php
app/Modules/Products/Filament/Resources/Brands/Pages/CreateBrand.php
app/Modules/Products/Filament/Resources/Brands/Pages/EditBrand.php
app/Modules/Products/Filament/Resources/Brands/Pages/ListBrands.php
app/Modules/Products/Filament/Resources/Brands/Pages/ViewBrand.php
app/Modules/Products/Filament/Resources/Brands/Schemas/BrandForm.php
app/Modules/Products/Filament/Resources/Brands/Schemas/BrandInfolist.php
app/Modules/Products/Filament/Resources/Brands/Tables/BrandsTable.php
app/Modules/Products/Filament/Resources/Categories/CategoryResource.php
app/Modules/Products/Filament/Resources/Categories/Pages/CreateCategory.php
app/Modules/Products/Filament/Resources/Categories/Pages/EditCategory.php
app/Modules/Products/Filament/Resources/Categories/Pages/ListCategories.php
app/Modules/Products/Filament/Resources/Categories/Pages/ViewCategory.php
app/Modules/Products/Filament/Resources/Categories/Schemas/CategoryForm.php
app/Modules/Products/Filament/Resources/Categories/Schemas/CategoryInfolist.php
app/Modules/Products/Filament/Resources/Categories/Tables/CategoriesTable.php
app/Modules/Products/Filament/Resources/Colors/ColorResource.php
app/Modules/Products/Filament/Resources/Colors/Pages/CreateColor.php
app/Modules/Products/Filament/Resources/Colors/Pages/EditColor.php
app/Modules/Products/Filament/Resources/Colors/Pages/ListColors.php
app/Modules/Products/Filament/Resources/Colors/Pages/ViewColor.php
app/Modules/Products/Filament/Resources/Colors/Schemas/ColorForm.php
app/Modules/Products/Filament/Resources/Colors/Schemas/ColorInfolist.php
app/Modules/Products/Filament/Resources/Colors/Tables/ColorsTable.php
app/Modules/Products/Filament/Resources/Products/Pages/CreateProduct.php
app/Modules/Products/Filament/Resources/Products/Pages/EditProduct.php
app/Modules/Products/Filament/Resources/Products/Pages/ListProducts.php
app/Modules/Products/Filament/Resources/Products/Pages/ProductAssignments.php
app/Modules/Products/Filament/Resources/Products/Pages/ViewProduct.php
app/Modules/Products/Filament/Resources/Products/ProductResource.php
app/Modules/Products/Filament/Resources/Products/RelationManagers/ImagesRelationManager.php
app/Modules/Products/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php
app/Modules/Products/Filament/Resources/Products/Schemas/ProductForm.php
app/Modules/Products/Filament/Resources/Products/Schemas/ProductInfolist.php
app/Modules/Products/Filament/Resources/Products/Tables/ProductsTable.php
app/Modules/Products/Filament/Resources/Sizes/Pages/CreateSize.php
app/Modules/Products/Filament/Resources/Sizes/Pages/EditSize.php
app/Modules/Products/Filament/Resources/Sizes/Pages/ListSizes.php
app/Modules/Products/Filament/Resources/Sizes/Pages/ViewSize.php
app/Modules/Products/Filament/Resources/Sizes/Schemas/SizeForm.php
app/Modules/Products/Filament/Resources/Sizes/Schemas/SizeInfolist.php
app/Modules/Products/Filament/Resources/Sizes/SizeResource.php
app/Modules/Products/Filament/Resources/Sizes/Tables/SizesTable.php
app/Modules/Products/Policies/BrandPolicy.php
app/Modules/Products/Policies/CatalogPolicy.php
app/Modules/Products/Policies/CategoryPolicy.php
app/Modules/Products/Policies/ColorPolicy.php
app/Modules/Products/Policies/ProductImagePolicy.php
app/Modules/Products/Policies/ProductPolicy.php
app/Modules/Products/Policies/ProductVariantPolicy.php
app/Modules/Products/Policies/SizePolicy.php
app/Modules/Products/Services/ProductAssignmentManager.php
app/Modules/Products/Services/ProductCatalogManager.php
app/Modules/Products/Services/ProductImageManager.php
app/Modules/Products/Services/ProductPermissions.php
app/Modules/Products/Services/ProductReferenceChecker.php
app/Modules/Products/Services/ProductVariantManager.php
app/Modules/Products/Services/ProductsAccess.php
tests/Feature/ProductsManagementTest.php
```

Modified existing files (16):

```text
README.md
app/Modules/AccessManagement/Filament/Resources/Modules/ModuleResource.php
app/Modules/AccessManagement/Models/User.php
app/Modules/AccessManagement/Services/ModuleRegistry.php
app/Modules/AccessManagement/Services/SuperAdminProvisioner.php
app/Modules/Products/Models/Brand.php
app/Modules/Products/Models/Category.php
app/Modules/Products/Models/Color.php
app/Modules/Products/Models/Product.php
app/Modules/Products/Models/ProductImage.php
app/Modules/Products/Models/ProductVariant.php
app/Modules/Products/Models/Size.php
app/Modules/Products/Providers/ProductManagementServiceProvider.php
app/Providers/Filament/AdminPanelProvider.php
tests/Feature/AccessManagement/AccessManagementTest.php
tests/Feature/ModulesPermissionsTest.php
```
