# Multi-AI Provider Support

This package now supports multiple AI hosting channels, allowing you to use different AI providers for natural language processing. Here's how to configure and use each provider:

## Supported Providers

1. **OpenAI** - Official OpenAI API
2. **Azure OpenAI** - Microsoft Azure OpenAI Service
3. **Ollama** - Local AI models via Ollama
4. **LM Studio** - Local AI models via LM Studio
5. **Custom** - Any OpenAI-compatible API

## Configuration

Set your preferred provider in your `.env` file:

```env
FILAMENT_NL_FILTER_PROVIDER=ollama
```

## Provider-Specific Configuration

### 1. OpenAI (Default)

```env
FILAMENT_NL_FILTER_PROVIDER=openai
OPENAI_API_KEY=your_openai_api_key
OPENAI_ORGANIZATION=your_organization_id
FILAMENT_NL_FILTER_MODEL=gpt-3.5-turbo
FILAMENT_NL_FILTER_MAX_TOKENS=500
FILAMENT_NL_FILTER_TEMPERATURE=0.1
```

### 2. Azure OpenAI

```env
FILAMENT_NL_FILTER_PROVIDER=azure
AZURE_OPENAI_API_KEY=your_azure_api_key
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_VERSION=2024-02-15-preview
AZURE_OPENAI_DEPLOYMENT_NAME=your_deployment_name
FILAMENT_NL_FILTER_MAX_TOKENS=500
FILAMENT_NL_FILTER_TEMPERATURE=0.1
```

### 3. Ollama (Local Models)

```env
FILAMENT_NL_FILTER_PROVIDER=ollama
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=llama2
FILAMENT_NL_FILTER_MAX_TOKENS=500
FILAMENT_NL_FILTER_TEMPERATURE=0.1
OLLAMA_TIMEOUT=30
```

**Popular Ollama Models:**

- `llama2` - Meta's Llama 2
- `codellama` - Code-focused Llama model
- `mistral` - Mistral 7B
- `neural-chat` - Intel's Neural Chat
- `gemma` - Google's Gemma model

### 4. LM Studio

```env
FILAMENT_NL_FILTER_PROVIDER=lmstudio
LMSTUDIO_HOST=http://localhost:1234
LMSTUDIO_MODEL=local-model
LMSTUDIO_API_KEY=optional_api_key
FILAMENT_NL_FILTER_MAX_TOKENS=500
FILAMENT_NL_FILTER_TEMPERATURE=0.1
LMSTUDIO_TIMEOUT=30
```

**LM Studio Setup:**

1. Download and install LM Studio
2. Load your preferred model
3. Start the local server (usually on port 1234)
4. Configure the model name in your environment

### 5. Custom Provider

```env
FILAMENT_NL_FILTER_PROVIDER=custom
CUSTOM_AI_ENDPOINT=https://your-api-endpoint.com/v1/chat/completions
CUSTOM_AI_MODEL=your-model-name
CUSTOM_AI_API_KEY=your_api_key
CUSTOM_AI_FORMAT=openai
CUSTOM_AI_AUTH_HEADER=Authorization
CUSTOM_AI_AUTH_PREFIX=Bearer
CUSTOM_AI_RESPONSE_PATH=choices.0.message.content
FILAMENT_NL_FILTER_MAX_TOKENS=500
FILAMENT_NL_FILTER_TEMPERATURE=0.1
CUSTOM_AI_TIMEOUT=30
```

**Supported Custom Formats:**

- `openai` - OpenAI-compatible API
- `anthropic` - Anthropic Claude API
- `custom` - Custom request/response format

## Usage Examples

### Basic Usage (Automatic Provider Selection)

```php
use Inerba\FilamentNaturalLanguageFilter\Filters\NaturalLanguageFilter;

// The filter will automatically use the configured provider
NaturalLanguageFilter::make()
    ->availableColumns(['name', 'email', 'created_at'])
    ->availableRelations(['orders', 'profile'])
```

### Direct Provider Usage

```php
use Inerba\FilamentNaturalLanguageFilter\Services\ProcessorFactory;

// Create a specific processor
$ollamaProcessor = ProcessorFactory::createWithProvider('ollama');
$filters = $ollamaProcessor->processQuery('users created after 2023', ['name', 'created_at']);

// Check provider status
$status = ProcessorFactory::getProviderStatus();
// Returns: ['ollama' => ['available' => true, 'class' => 'OllamaProcessor'], ...]

// Get best available provider
$bestProvider = ProcessorFactory::getBestAvailableProvider();
// Returns: 'ollama' (if available) or null
```

### Dependency Injection

```php
use Inerba\FilamentNaturalLanguageFilter\Services\OllamaProcessor;

class MyController
{
    public function __construct(
        private OllamaProcessor $processor
    ) {}

    public function processQuery(string $query)
    {
        return $this->processor->processQuery($query, ['name', 'email']);
    }
}
```

## Provider Comparison

| Provider         | Pros                         | Cons                          | Best For                     |
| ---------------- | ---------------------------- | ----------------------------- | ---------------------------- |
| **OpenAI**       | Reliable, fast, high quality | Requires API key, costs money | Production applications      |
| **Azure OpenAI** | Enterprise-grade, compliant  | Requires Azure setup          | Enterprise applications      |
| **Ollama**       | Free, local, private         | Requires local setup, slower  | Development, privacy-focused |
| **LM Studio**    | Easy GUI, local models       | Requires local setup          | Local development            |
| **Custom**       | Flexible, any API            | Requires configuration        | Specialized APIs             |

## Troubleshooting

### Ollama Issues

1. **Connection refused**: Make sure Ollama is running (`ollama serve`)
2. **Model not found**: Pull the model first (`ollama pull llama2`)
3. **Slow responses**: Try smaller models or increase timeout

### LM Studio Issues

1. **Connection refused**: Start LM Studio server
2. **Model not loaded**: Load a model in LM Studio GUI
3. **Wrong model name**: Check the model name in LM Studio

### Custom Provider Issues

1. **Invalid response**: Check `CUSTOM_AI_RESPONSE_PATH`
2. **Auth errors**: Verify `CUSTOM_AI_API_KEY` and auth headers
3. **Format issues**: Ensure `CUSTOM_AI_FORMAT` matches your API

## Advanced Configuration

### Custom Request Format

For non-standard APIs, you can define custom request formats:

```php
// In config/filament-natural-language-filter.php
'custom' => [
    'api_format' => 'custom',
    'request_format' => [
        'prompt' => '{{prompt}}',
        'max_length' => '{{max_tokens}}',
        'temperature' => '{{temperature}}',
    ],
    'response_path' => 'result.text',
],
```

### Fallback Providers

The system automatically falls back to available providers if the primary one fails:

```php
// Priority order: azure -> openai -> ollama -> lmstudio -> custom
$processor = ProcessorFactory::create(); // Uses configured provider or best available
```

## Performance Tips

1. **Use caching**: Enable cache in config for repeated queries
2. **Choose appropriate models**: Smaller models for faster responses
3. **Set timeouts**: Adjust timeouts based on your provider
4. **Monitor usage**: Track API usage for cost optimization

## Security Considerations

1. **API Keys**: Never commit API keys to version control
2. **Local Models**: Ollama/LM Studio are more private but require local resources
3. **Network Security**: Use HTTPS for all API endpoints
4. **Rate Limiting**: Implement rate limiting for production use
