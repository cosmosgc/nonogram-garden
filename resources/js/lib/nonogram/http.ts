export async function readJsonResponse(response: Response): Promise<any> {
    const contentType = response.headers?.get('content-type');
    if (contentType && !/^application\/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)/i.test(contentType)) {
        throw new Error(`Server returned ${contentType} instead of JSON (HTTP ${response.status}). Check the route URL and sign-in state.`);
    }

    return response.json();
}
