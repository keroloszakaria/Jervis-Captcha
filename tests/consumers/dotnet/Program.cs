using System.Text.Json;
using Jervis.Captcha;
try
{
    using var document = JsonDocument.Parse(await Console.In.ReadToEndAsync());
    var input = document.RootElement;
    string Value(string name) => input.GetProperty(name).GetString() ?? throw new JsonException();
    using var client = new JervisCaptchaClient(Value("endpoint"), Value("siteKey"), Value("secret"));
    object result;
    if (Value("operation") == "create")
        result = await client.CreateSessionAsync(Value("action"), Value("binding"), "pow");
    else
    {
        if (!await client.VerifyAsync(Value("token"), Value("action"), Value("binding")))
            throw new InvalidOperationException("Verification rejected");
        result = new { success = true, action = Value("action") };
    }
    Console.WriteLine(JsonSerializer.Serialize(result, new JsonSerializerOptions(JsonSerializerDefaults.Web)));
    return 0;
}
catch
{
    Console.Error.WriteLine("Verification rejected");
    return 1;
}
