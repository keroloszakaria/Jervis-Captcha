using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Jervis.Captcha;

public sealed record CaptchaSession(string SessionToken, long ExpiresInMs);
public sealed record CaptchaVerification(bool Success, string Action);

/// <summary>Server-only client. A timeout is indeterminate; never retry proof consumption automatically.</summary>
public sealed class JervisCaptchaClient : IDisposable
{
    private readonly HttpClient http;
    private readonly string siteKey;
    public JervisCaptchaClient(string endpoint, string siteKey, string secret)
    {
        var uri = new Uri(endpoint.TrimEnd('/') + "/", UriKind.Absolute);
        if ((uri.Scheme != "http" && uri.Scheme != "https") || uri.UserInfo.Length != 0 || uri.Query.Length != 0 || uri.Fragment.Length != 0 || secret.Length < 32 || string.IsNullOrEmpty(siteKey))
            throw new ArgumentException("Invalid Jervis Captcha configuration");
        this.siteKey = siteKey;
        http = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false, UseProxy = false }) { BaseAddress = uri, Timeout = TimeSpan.FromSeconds(5) };
        http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", secret);
    }
    private async Task<T> PostAsync<T>(string path, object data, CancellationToken cancellationToken)
    {
        using var response = await http.PostAsJsonAsync(path, data, cancellationToken);
        response.EnsureSuccessStatusCode();
        var value = await response.Content.ReadFromJsonAsync<T>(cancellationToken: cancellationToken);
        return value ?? throw new JsonException("Invalid verification response");
    }
    public async Task<CaptchaSession> CreateSessionAsync(string action, string binding, string? type = null, CancellationToken cancellationToken = default)
    {
        var body = new Dictionary<string, object> { ["siteKey"] = siteKey, ["action"] = action, ["binding"] = binding };
        if (type is not null) body["type"] = type;
        var session = await PostAsync<CaptchaSession>("v1/sessions", body, cancellationToken);
        if (!Regex.IsMatch(session.SessionToken ?? "", "\\A[A-Za-z0-9_-]{43}\\z") || session.ExpiresInMs <= 0) throw new JsonException("Invalid verification response");
        return session;
    }
    public async Task<bool> VerifyAsync(string token, string action, string binding, CancellationToken cancellationToken = default)
    {
        var result = await PostAsync<CaptchaVerification>("v1/siteverify", new { siteKey, token, action, binding }, cancellationToken);
        return result.Success && result.Action == action;
    }
    public void Dispose() => http.Dispose();
}
