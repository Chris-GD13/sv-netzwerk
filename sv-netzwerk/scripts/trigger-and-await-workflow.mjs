const args = process.argv.slice(2);
const argValue = (name, fallback = '') => {
  const token = args.find((item) => item.startsWith(`--${name}=`));
  return token ? token.slice(name.length + 3) : fallback;
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const repo = argValue('repo');
const workflow = argValue('workflow');
const ref = argValue('ref', 'main');
const headSha = argValue('head-sha');
const timeoutSeconds = Number(argValue('timeout-seconds', '2400'));
const pollSeconds = Number(argValue('poll-seconds', '10'));
const event = argValue('event', 'workflow_dispatch');
const token = process.env.GITHUB_TOKEN;

if (!token) throw new Error('GITHUB_TOKEN fehlt.');
if (!repo) throw new Error('--repo ist erforderlich.');
if (!workflow) throw new Error('--workflow ist erforderlich.');
if (!headSha) throw new Error('--head-sha ist erforderlich.');

const api = async (method, endpoint, body) => {
  const response = await fetch(`https://api.github.com${endpoint}`, {
    method,
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`GitHub API ${method} ${endpoint} fehlgeschlagen (${response.status}): ${errorText}`);
  }

  if (response.status === 204) return null;
  return response.json();
};

const dispatchAt = Date.now();
await api('POST', `/repos/${repo}/actions/workflows/${encodeURIComponent(workflow)}/dispatches`, { ref });
console.log(`Workflow ausgelöst: ${workflow} auf ${ref}`);

const timeoutAt = Date.now() + timeoutSeconds * 1000;
let runId = '';
let runHtmlUrl = '';
let runStatus = '';
let runConclusion = '';

while (Date.now() < timeoutAt) {
  const payload = await api(
    'GET',
    `/repos/${repo}/actions/workflows/${encodeURIComponent(workflow)}/runs?event=${encodeURIComponent(event)}&branch=${encodeURIComponent(ref)}&per_page=50`,
  );

  const workflowRuns = payload?.workflow_runs ?? [];
  const selected = workflowRuns
    .filter((run) => run.head_sha === headSha)
    .filter((run) => new Date(run.created_at).getTime() >= dispatchAt - 120000)
    .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())[0];

  if (!selected) {
    await sleep(pollSeconds * 1000);
    continue;
  }

  runId = String(selected.id);
  runHtmlUrl = selected.html_url ?? '';
  runStatus = selected.status ?? '';
  runConclusion = selected.conclusion ?? '';

  console.log(`Deploy-Run gefunden: id=${runId} status=${runStatus} conclusion=${runConclusion || 'pending'}`);

  if (runStatus === 'completed') {
    if (runConclusion !== 'success') {
      throw new Error(`Deploy-Workflow fehlgeschlagen: ${runConclusion} (${runHtmlUrl || 'keine URL'})`);
    }
    process.stdout.write(JSON.stringify({
      runId,
      runHtmlUrl,
      runStatus,
      runConclusion,
      workflow,
      headSha,
    }));
    process.exit(0);
  }

  await sleep(pollSeconds * 1000);
}

throw new Error(`Timeout beim Warten auf ${workflow} für Commit ${headSha}.`);
