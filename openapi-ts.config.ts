export interface OpenApiConfig {
    input: string;
    output: { path: string };
    plugins: readonly string[];
}

const config: OpenApiConfig = {
    input: process.env.OPENAPI_SPEC_URL ?? 'http://127.0.0.1:8000/docs/api.json',
    output: { path: 'resources/js/api/generated' },
    plugins: ['@hey-api/typescript', '@hey-api/client-fetch'],
};

export default config;
