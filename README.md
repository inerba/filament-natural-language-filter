# Filament Natural Language Filter

A simple Filament filter that converts natural language text into database queries using AI.

## Installation

```bash
composer require inerba/filament-natural-language-filter
```

## Configuration

1. Publish the config file:

```bash
php artisan vendor:publish --tag="filament-natural-language-filter-config"
```

2. Add your AI provider configuration to your `.env` file:

**For OpenAI:**

```env
FILAMENT_NL_FILTER_PROVIDER=openai
OPENAI_API_KEY=your-openai-api-key-here
```

**For Azure OpenAI:**

```env
FILAMENT_NL_FILTER_PROVIDER=azure
AZURE_OPENAI_API_KEY=your-azure-openai-api-key
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com/
AZURE_OPENAI_DEPLOYMENT_NAME=your-deployment-name
```

## Usage

Add the filter to your Filament table:

```php
use Inerba\FilamentNaturalLanguageFilter\Filters\NaturalLanguageFilter;

public function table(Table $table): Table
{
    return $table
        ->columns([
            // your columns
        ])
        ->filters([
            NaturalLanguageFilter::make()
                ->availableColumns([
                    'id',
                    'name',
                    'email',
                    'status',
                    'created_at',
                    'updated_at'
                ])
                ->availableRelations([
                    'orders',
                    'posts',
                    'profile'
                ])
        ]);
}
```

### Advanced Features

#### Relationship Filtering

```php
// Enable relationship filtering
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email'])
    ->availableRelations(['orders', 'posts'])
    // Now supports: "users with orders over $100"
    // "posts by active users"
    // "products in electronics category"
```

#### Boolean Logic Support

```php
// Enable boolean logic (AND/OR operations)
// Supports: "users named john OR email contains gmail"
// "active users AND created after 2023"
// "status is pending AND (amount > 100 OR priority is high)"
```

#### Aggregation Queries

```php
// Enable aggregation operations
// Supports: "top 10 users by order count"
// "products with highest sales"
// "users with most posts"
```

#### Query Suggestions

```php
// Enable AI-powered query suggestions
// Provides autocomplete and intelligent suggestions
// based on available columns and relationships
```

## Real-World Implementation Examples

### E-commerce Product Management

```php
// ProductResource.php
use Inerba\FilamentNaturalLanguageFilter\Filters\NaturalLanguageFilter;

public function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('name'),
            TextColumn::make('category.name'),
            TextColumn::make('price'),
            TextColumn::make('stock_quantity'),
            TextColumn::make('status'),
            TextColumn::make('created_at'),
        ])
        ->filters([
            NaturalLanguageFilter::make()
                ->availableColumns([
                    'name', 'price', 'stock_quantity', 'status',
                    'created_at', 'updated_at'
                ])
                ->availableRelations(['category', 'reviews', 'orders'])
                ->liveSearch()
        ]);
}

// Now supports queries like:
// - "products in electronics category"
// - "out of stock products"
// - "products with price over $100"
// - "products with more than 10 reviews"
// - "best selling products this month"
```

### User Management System

```php
// UserResource.php
public function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('name'),
            TextColumn::make('email'),
            TextColumn::make('status'),
            TextColumn::make('created_at'),
            TextColumn::make('last_login_at'),
        ])
        ->filters([
            NaturalLanguageFilter::make()
                ->availableColumns([
                    'name', 'email', 'status', 'role',
                    'created_at', 'last_login_at', 'email_verified_at'
                ])
                ->availableRelations(['orders', 'posts', 'profile', 'subscriptions'])
                ->submitSearch()
        ]);
}

// Supports queries like:
// - "active users with gmail email"
// - "users who haven't logged in this month"
// - "premium subscribers with orders over $500"
// - "users created this week AND verified"
```

### Content Management System

```php
// PostResource.php
public function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('title'),
            TextColumn::make('author.name'),
            TextColumn::make('status'),
            TextColumn::make('published_at'),
            TextColumn::make('views_count'),
        ])
        ->filters([
            NaturalLanguageFilter::make()
                ->availableColumns([
                    'title', 'content', 'status', 'views_count',
                    'created_at', 'published_at'
                ])
                ->availableRelations(['author', 'comments', 'tags', 'category'])
                ->liveSearch()
        ]);
}

// Supports queries like:
// - "published posts by john"
// - "posts with more than 100 views"
// - "draft posts created this week"
// - "posts with comments from verified users"
```

### Order Management System

```php
// OrderResource.php
public function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('order_number'),
            TextColumn::make('customer.name'),
            TextColumn::make('status'),
            TextColumn::make('total_amount'),
            TextColumn::make('created_at'),
        ])
        ->filters([
            NaturalLanguageFilter::make()
                ->availableColumns([
                    'order_number', 'status', 'total_amount',
                    'created_at', 'shipped_at', 'delivered_at'
                ])
                ->availableRelations(['customer', 'items', 'shipping_address'])
                ->submitSearch()
        ]);
}

// Supports queries like:
// - "orders over $1000"
// - "pending orders from premium customers"
// - "orders shipped this week"
// - "orders with more than 5 items"
```

## Advanced Configuration Examples

### Custom Column Mappings

```php
// Map natural language terms to database columns
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status'])
    ->columnMappings([
        'user' => 'name',
        'email address' => 'email',
        'active' => 'status',
        'inactive' => 'status',
        'created' => 'created_at',
        'updated' => 'updated_at'
    ])
```

### Performance Optimization

```php
// For large datasets, use submit mode
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status'])
    ->submitSearch() // Reduces API calls
    ->availableRelations(['orders']) // Limit relationships
```

### Multi-Language Support

```php
// The filter automatically detects and handles multiple languages
// No additional configuration needed!

// English: "active users"
// Spanish: "usuarios activos"
// French: "utilisateurs actifs"
// German: "aktive benutzer"
// Arabic: "المستخدمون النشطون"
```

## Code Implementation Patterns

### Custom Filter Logic

```php
// Extend the filter for custom behavior
class CustomNaturalLanguageFilter extends NaturalLanguageFilter
{
    protected function applyFilter(Builder $query, array $filter): void
    {
        // Add custom logic before applying filter
        if ($filter['column'] === 'status' && $filter['value'] === 'active') {
            // Custom logic for active status
            $query->where('status', 'active')
                  ->where('email_verified_at', '!=', null);
            return;
        }

        // Apply standard filter
        parent::applyFilter($query, $filter);
    }
}
```

### Integration with Other Filters

```php
public function table(Table $table): Table
{
    return $table
        ->filters([
            // Combine with other Filament filters
            SelectFilter::make('status')
                ->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ]),

            DateFilter::make('created_at'),

            // Natural language filter works alongside others
            NaturalLanguageFilter::make()
                ->availableColumns(['name', 'email', 'status'])
                ->liveSearch()
        ]);
}
```

### API Integration

```php
// Use in API endpoints
class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->has('natural_query')) {
            $filter = NaturalLanguageFilter::make()
                ->availableColumns(['name', 'email', 'status'])
                ->availableRelations(['orders']);

            $query = $filter->apply($query, [
                'query' => $request->get('natural_query')
            ]);
        }

        return $query->paginate();
    }
}
```

### Search Modes

You can configure how the filter triggers searches:

#### Submit Mode (Default) - Search on Enter key

```php
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status'])
    ->submitSearch() // Users press Enter to search
```

#### Live Mode - Search as you type

```php
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status'])
    ->liveSearch() // Search happens automatically as user types
```

#### Manual Mode Configuration

```php
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status'])
    ->searchMode('live') // or 'submit'
```

### When to Use Each Mode

**Submit Mode (Default)** - Best for:

- Large datasets where live search might be slow
- Complex queries that users want to perfect before searching
- Reducing API calls to OpenAI (only search when user is ready)

**Live Mode** - Best for:

- Instant feedback and better user experience
- Smaller datasets where performance isn't a concern
- Users who prefer immediate results as they type

## How it works

1. **User enters natural language**: "show users named john created after 2023"
2. **AI processes the text**: Converts it to structured filters based on your available columns
3. **Database query is built**: `WHERE name LIKE '%john%' AND created_at > '2023-01-01'`
4. **Results are filtered**: Table shows matching records

## Examples

### Basic Filtering Examples

**Simple Text Searches:**

- "users named john" → `WHERE name LIKE '%john%'`
- "active users" → `WHERE status = 'active'`
- "email contains gmail" → `WHERE email LIKE '%gmail%'`
- "products in electronics" → `WHERE category LIKE '%electronics%'`

**Date Filtering:**

- "created after 2023" → `WHERE created_at > '2023-01-01'`
- "created yesterday" → `WHERE DATE(created_at) = '2023-12-31'`
- "created this week" → `WHERE created_at >= '2023-12-25'`
- "created between january and march" → `WHERE created_at BETWEEN '2023-01-01' AND '2023-03-31'`

**Numeric Comparisons:**

- "orders over $100" → `WHERE amount > 100`
- "users with age between 18 and 65" → `WHERE age BETWEEN 18 AND 65`
- "products with price less than 50" → `WHERE price < 50`

### Advanced Relationship Filtering

**Cross-Model Queries:**

```php
// Setup with relationships
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status'])
    ->availableRelations(['orders', 'posts', 'profile'])
```

**Relationship Examples:**

- "users with orders over $100" → `WHERE EXISTS (SELECT 1 FROM orders WHERE user_id = users.id AND amount > 100)`
- "posts by active users" → `WHERE EXISTS (SELECT 1 FROM users WHERE id = posts.user_id AND status = 'active')`
- "products in electronics category" → `WHERE EXISTS (SELECT 1 FROM categories WHERE id = products.category_id AND name = 'electronics')`
- "users with more than 5 posts" → `WHERE (SELECT COUNT(*) FROM posts WHERE user_id = users.id) > 5`

### Boolean Logic Examples

**AND Operations:**

- "active users AND created after 2023" → `WHERE status = 'active' AND created_at > '2023-01-01'`
- "users with gmail email AND verified" → `WHERE email LIKE '%gmail%' AND verified = 1`

**OR Operations:**

- "users named john OR email contains gmail" → `WHERE name LIKE '%john%' OR email LIKE '%gmail%'`
- "status is pending OR status is processing" → `WHERE status = 'pending' OR status = 'processing'`

**Complex Logic:**

- "status is pending AND (amount > 100 OR priority is high)" → `WHERE status = 'pending' AND (amount > 100 OR priority = 'high')`
- "active users AND (created this year OR has orders)" → `WHERE status = 'active' AND (YEAR(created_at) = 2023 OR EXISTS (SELECT 1 FROM orders WHERE user_id = users.id))`

### Aggregation Query Examples

**Count Operations:**

- "top 10 users by order count" → `SELECT users.*, COUNT(orders.id) as order_count FROM users LEFT JOIN orders ON users.id = orders.user_id GROUP BY users.id ORDER BY order_count DESC LIMIT 10`
- "users with more than 5 posts" → `WHERE (SELECT COUNT(*) FROM posts WHERE user_id = users.id) > 5`

**Sum/Average Operations:**

- "products with highest sales" → `SELECT products.*, SUM(order_items.quantity) as total_sales FROM products JOIN order_items ON products.id = order_items.product_id GROUP BY products.id ORDER BY total_sales DESC`
- "users with average order value over $200" → `WHERE (SELECT AVG(amount) FROM orders WHERE user_id = users.id) > 200`

**Min/Max Operations:**

- "oldest users" → `ORDER BY created_at ASC`
- "newest products" → `ORDER BY created_at DESC`
- "highest priced products" → `ORDER BY price DESC`

## Universal Language Support 🌍

The filter supports **ANY language** with automatic AI translation and understanding:

### Multi-Language Examples

**English:**

- "show users named john" → `WHERE name LIKE '%john%'`
- "created after 2023" → `WHERE created_at > '2023-01-01'`

**Arabic (العربية):**

- "الاسم يحتوي على أحمد" → `WHERE name LIKE '%أحمد%'`
- "أنشئ بعد 2023" → `WHERE created_at > '2023-01-01'`

**Spanish (Español):**

- "usuarios con nombre juan" → `WHERE name LIKE '%juan%'`
- "creado después de 2023" → `WHERE created_at > '2023-01-01'`

**French (Français):**

- "nom contient marie" → `WHERE name LIKE '%marie%'`
- "créé après 2023" → `WHERE created_at > '2023-01-01'`

**German (Deutsch):**

- "benutzer mit namen hans" → `WHERE name LIKE '%hans%'`
- "erstellt nach 2023" → `WHERE created_at > '2023-01-01'`

**Chinese (中文):**

- "姓名包含张三" → `WHERE name LIKE '%张三%'`
- "2023年后创建" → `WHERE created_at > '2023-01-01'`

**Japanese (日本語):**

- "田中という名前のユーザー" → `WHERE name LIKE '%田中%'`
- "2023年以降に作成" → `WHERE created_at > '2023-01-01'`

### How It Works

1. **AI Language Detection**: Automatically detects the input language
2. **Natural Understanding**: Maps language-specific keywords to operators
3. **Value Preservation**: Keeps original values in their native language/script
4. **Mixed Language**: Handles mixed-language queries seamlessly

### Mixed Language Queries

The AI can handle mixed-language queries naturally:

- "name يحتوي على john" ✅
- "usuario con email gmail.com" ✅
- "姓名 contains 张三" ✅

## AI Provider Support

The package supports both **OpenAI** and **Azure OpenAI** services. You can choose your preferred provider:

### OpenAI (Default)

```env
FILAMENT_NL_FILTER_PROVIDER=openai
OPENAI_API_KEY=your-openai-api-key-here
```

### Azure OpenAI

```env
FILAMENT_NL_FILTER_PROVIDER=azure
AZURE_OPENAI_API_KEY=your-azure-openai-api-key
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com/
AZURE_OPENAI_DEPLOYMENT_NAME=your-deployment-name
AZURE_OPENAI_API_VERSION=2024-02-15-preview
```

## Configuration Options

```php
// config/filament-natural-language-filter.php
return [
    'provider' => 'openai', // 'openai' or 'azure'
    'model' => 'gpt-3.5-turbo', // Model name
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'max_tokens' => 500,
        'temperature' => 0.1,
    ],
    'azure' => [
        'api_key' => env('AZURE_OPENAI_API_KEY'),
        'endpoint' => env('AZURE_OPENAI_ENDPOINT'),
        'deployment_name' => env('AZURE_OPENAI_DEPLOYMENT_NAME'),
        'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-02-15-preview'),
        'max_tokens' => 500,
        'temperature' => 0.1,
    ],
    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // 1 hour
    ],
];
```

### Environment Variables

**For OpenAI:**

```env
FILAMENT_NL_FILTER_PROVIDER=openai
OPENAI_API_KEY=your-openai-api-key-here
```

**For Azure OpenAI:**

```env
FILAMENT_NL_FILTER_PROVIDER=azure
AZURE_OPENAI_API_KEY=your-azure-openai-api-key
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com/
AZURE_OPENAI_DEPLOYMENT_NAME=your-deployment-name
AZURE_OPENAI_API_VERSION=2024-02-15-preview
FILAMENT_NL_FILTER_UNIVERSAL_SUPPORT=true
FILAMENT_NL_FILTER_AUTO_DETECT_DIRECTION=true
FILAMENT_NL_FILTER_PRESERVE_ORIGINAL_VALUES=true
```

**Advanced Features Configuration:**

```env
# Relationship Filtering
FILAMENT_NL_FILTER_RELATIONSHIP_FILTERING=true
FILAMENT_NL_FILTER_MAX_RELATION_DEPTH=2
FILAMENT_NL_FILTER_ALLOWED_RELATIONS=orders,posts,profile

# Boolean Logic Support
FILAMENT_NL_FILTER_BOOLEAN_LOGIC=true
FILAMENT_NL_FILTER_MAX_CONDITIONS=10

# Aggregation Queries
FILAMENT_NL_FILTER_AGGREGATION_QUERIES=true

# Query Suggestions
FILAMENT_NL_FILTER_QUERY_SUGGESTIONS=true
FILAMENT_NL_FILTER_MAX_SUGGESTIONS=5
FILAMENT_NL_FILTER_CACHE_SUGGESTIONS=true
```

## Version Management

The package includes automatic version management. You can bump versions manually or automatically:

### Manual Version Bumping

```bash
# Bump patch version (1.0.0 → 1.0.1)
composer run version:patch

# Bump minor version (1.0.0 → 1.1.0)
composer run version:minor

# Bump major version (1.0.0 → 2.0.0)
composer run version:major

# Show current version
composer run version:show
```

### Automatic Version Bumping

The package includes a Git pre-push hook that automatically bumps the patch version on each push to the main branch.

### Quick Bump and Push

```bash
# Simple script to bump and push
./scripts/bump-and-push.sh patch
./scripts/bump-and-push.sh minor
./scripts/bump-and-push.sh major
```

## Troubleshooting & FAQ

### Common Issues

#### 1. Filter Not Working

**Problem:** Natural language filter doesn't process queries
**Solutions:**

```bash
# Check if OpenAI/Azure is configured
php artisan config:show filament-natural-language-filter

# Verify API keys
echo $OPENAI_API_KEY
echo $AZURE_OPENAI_API_KEY
```

#### 2. Slow Performance

**Problem:** Filter is slow with large datasets
**Solutions:**

```php
// Use submit mode instead of live mode
NaturalLanguageFilter::make()
    ->submitSearch() // Reduces API calls

// Limit available columns
->availableColumns(['name', 'email', 'status']) // Only essential columns

// Disable expensive features
FILAMENT_NL_FILTER_RELATIONSHIP_FILTERING=false
FILAMENT_NL_FILTER_AGGREGATION_QUERIES=false
```

#### 3. AI Not Understanding Queries

**Problem:** AI returns empty results or wrong filters
**Solutions:**

```php
// Add more context to available columns
->availableColumns([
    'name', 'email', 'status', 'created_at',
    'first_name', 'last_name', 'full_name' // Add variations
])

// Enable debug logging
FILAMENT_NL_FILTER_LOGGING=true
FILAMENT_NL_FILTER_LOG_LEVEL=debug
```

#### 4. Relationship Filtering Issues

**Problem:** Relationship queries don't work
**Solutions:**

```php
// Ensure relationships are properly defined
// In your model:
public function orders()
{
    return $this->hasMany(Order::class);
}

// In the filter:
->availableRelations(['orders']) // Use exact relationship names
```

#### 5. Cache Issues

**Problem:** Results are cached and not updating
**Solutions:**

```bash
# Clear cache
php artisan cache:clear

# Or disable caching temporarily
FILAMENT_NL_FILTER_CACHE_ENABLED=false
```

### Performance Optimization

#### Database Indexing

```sql
-- Add indexes for commonly filtered columns
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_created_at ON users(created_at);
CREATE INDEX idx_orders_amount ON orders(amount);
CREATE INDEX idx_orders_user_id ON orders(user_id);
```

#### Query Optimization

```php
// Use eager loading for relationships
$users = User::with(['orders', 'profile'])
    ->where('status', 'active')
    ->get();

// Limit relationship depth
FILAMENT_NL_FILTER_MAX_RELATION_DEPTH=1
```

#### Caching Strategy

```php
// Configure appropriate cache TTL
'cache' => [
    'enabled' => true,
    'ttl' => 3600, // 1 hour for production
    'prefix' => 'nl_filter',
],
```

### Debugging

#### Enable Debug Mode

```env
FILAMENT_NL_FILTER_LOGGING=true
FILAMENT_NL_FILTER_LOG_LEVEL=debug
LOG_CHANNEL=stack
```

#### Check Logs

```bash
# View logs
tail -f storage/logs/laravel.log | grep "Natural Language Filter"

# Or check specific log channel
tail -f storage/logs/filament-nl-filter.log
```

#### Test AI Connection

```php
// Test in tinker
php artisan tinker

// Test OpenAI
$processor = app(\Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface::class);
$result = $processor->processQuery('active users', ['name', 'status']);
dd($result);
```

### Advanced Configuration

#### Custom AI Prompts

```php
// Override system prompt in config
'custom_prompts' => [
    'system' => 'You are a specialized database query assistant for e-commerce...',
    'user' => 'Convert this e-commerce query: {query}',
],
```

#### Rate Limiting

```php
// Add rate limiting for API calls
'rate_limiting' => [
    'enabled' => true,
    'max_requests_per_minute' => 60,
    'max_requests_per_hour' => 1000,
],
```

#### Custom Validation

```php
// Add custom query validation
'validation' => [
    'min_length' => 3,
    'max_length' => 500,
    'blocked_words' => ['admin', 'delete', 'drop'],
    'allowed_operators' => ['equals', 'contains', 'greater_than'],
],
```

### Migration Guide

#### From Basic to Advanced

```php
// Before (basic)
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email'])

// After (advanced)
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status', 'created_at'])
    ->availableRelations(['orders', 'profile'])
    ->liveSearch()
```

#### Updating Configuration

```bash
# Publish updated config
php artisan vendor:publish --tag="filament-natural-language-filter-config" --force

# Update environment variables
cp .env.example .env
# Add new feature flags
```

### Best Practices

#### 1. Column Selection

```php
// Good: Specific, relevant columns
->availableColumns(['name', 'email', 'status', 'created_at'])

// Avoid: Too many columns
->availableColumns(['*']) // Don't do this
```

#### 2. Relationship Management

```php
// Good: Essential relationships only
->availableRelations(['orders', 'profile'])

// Avoid: Deep relationship chains
->availableRelations(['orders.items.product.category']) // Too deep
```

#### 3. Performance Monitoring

```php
// Add performance monitoring
use Illuminate\Support\Facades\Log;

// Log slow queries
if ($query->getQuery()->time > 1000) {
    Log::warning('Slow natural language query', [
        'query' => $queryText,
        'time' => $query->getQuery()->time
    ]);
}
```

## Testing

### Unit Tests

```bash
# Run the test suite
composer test

# Run with coverage
composer run test-coverage

# Run specific test
vendor/bin/phpunit --filter NaturalLanguageFilterTest
```

### Manual Testing

#### Test Basic Functionality

```php
// Test in tinker
php artisan tinker

// Test basic filtering
$filter = NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'status']);

$result = $filter->apply(User::query(), ['query' => 'active users']);
dd($result->toSql());
```

#### Test AI Integration

```php
// Test AI processor directly
$processor = app(\Inerba\FilamentNaturalLanguageFilter\Contracts\NaturalLanguageProcessorInterface::class);

// Test query processing
$filters = $processor->processQuery('users created after 2023', ['name', 'created_at']);
dd($filters);
```

#### Test Relationship Filtering

```php
// Test relationship queries
$filter = NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email'])
    ->availableRelations(['orders']);

$result = $filter->apply(User::query(), ['query' => 'users with orders over $100']);
dd($result->toSql());
```

### Integration Tests

```php
// Test in Filament resource
class UserResource extends Resource
{
    public function table(Table $table): Table
    {
        return $table
            ->filters([
                NaturalLanguageFilter::make()
                    ->availableColumns(['name', 'email', 'status'])
                    ->availableRelations(['orders'])
            ]);
    }
}

// Test the actual Filament page
// Visit /admin/users and try natural language queries
```

### Performance Testing

```php
// Test with large datasets
$startTime = microtime(true);

$users = User::with(['orders'])
    ->where('status', 'active')
    ->get();

$endTime = microtime(true);
$executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

Log::info("Query execution time: {$executionTime}ms");
```

### API Testing

```bash
# Test API endpoint
curl -X GET "http://localhost/api/users?natural_query=active%20users" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer your-token"
```

## Contributing

### Development Setup

```bash
# Clone the repository
git clone https://github.com/inerba/filament-natural-language-filter.git
cd filament-natural-language-filter

# Install dependencies
composer install

# Run tests
composer test

# Run code style checks
composer run cs-check

# Fix code style issues
composer run cs-fix

# Run static analysis
composer run phpstan
```

### Adding New Features

1. **Create Feature Branch**

```bash
git checkout -b feature/new-feature
```

2. **Implement Feature**

```php
// Add your new feature code
// Follow existing patterns and add tests
```

3. **Add Tests**

```php
// Create test file
tests/Feature/NewFeatureTest.php

// Write comprehensive tests
public function test_new_feature_works()
{
    // Test implementation
}
```

4. **Update Documentation**

```markdown
# Update README.md with new feature documentation

# Add examples and usage patterns
```

5. **Submit Pull Request**

```bash
git add .
git commit -m "Add new feature: description"
git push origin feature/new-feature
```

### Code Style

The package follows PSR-12 coding standards:

```bash
# Check code style
composer run cs-check

# Fix code style
composer run cs-fix
```

### Testing Guidelines

1. **Write Tests First** (TDD approach)
2. **Test Edge Cases** (empty queries, invalid data)
3. **Test Performance** (large datasets, complex queries)
4. **Test Error Handling** (API failures, invalid configurations)

### Release Process

1. **Update Version**

```bash
composer run version:patch  # or minor/major
```

2. **Update Changelog**

```markdown
# Add to CHANGELOG.md

## [1.0.2] - 2024-01-01

### Added

- New feature description

### Fixed

- Bug fix description
```

3. **Create Release**

```bash
git tag v1.0.2
git push origin v1.0.2
```

## Requirements

- PHP 8.1+
- Laravel 10+
- Filament 3+
- OpenAI API key or Azure OpenAI credentials

## License

MIT
