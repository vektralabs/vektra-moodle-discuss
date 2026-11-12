# vektra-lms-moodle

The Moodle provider of Vektra's `LmsProvider` Protocol (vektra-stack ADR-0033): the web-service
client, the 4.3 and 5.2 response models, the breaker and rate cap, the capability probes and the
service-account items of the setup check.

It is not part of the platform, and it is not part of the Moodle plugin in this repository: the
plugin's release zip excludes `provider/`. The default Vektra image does not contain it; the
`lms-moodle` image variant installs the wheel released from here, pinned by version and sha256. A
deployment selects it with `VEKTRA_ASSESS_LMS_PROVIDER=moodle` and configures it with the
`VEKTRA_LMS_MOODLE_*` settings (vektra-stack `docs/reference/configuration.md`). The former
`VEKTRA_ASSESS_MOODLE_*`, `VEKTRA_ASSESS_WS_*` and `VEKTRA_ASSESS_BREAKER_*` names are refused at
startup.

## Development and tests

The provider depends on `vektra-shared`, and its joint suites import the assessment vertical's
test harness (`vektra-assess/tests`). Both come from a vektra-stack checkout at the commit pinned
in [`vektra-stack.ref`](vektra-stack.ref); `VEKTRA_STACK_ROOT` points at it.

```bash
git clone https://github.com/vektralabs/vektra-stack.git ../vektra-stack-ref
git -C ../vektra-stack-ref checkout --detach "$(cat provider/vektra-stack.ref)"
export VEKTRA_STACK_ROOT="$PWD/../vektra-stack-ref"
(cd "$VEKTRA_STACK_ROOT" && uv sync --dev --frozen && uv pip install --no-deps -e "$OLDPWD/provider")

# the joint suites start a real Postgres (Docker) and run `uv run alembic` in the checkout:
# UV_NO_SYNC keeps uv from syncing the provider away in the middle of the run
UV_NO_SYNC=1 "$VEKTRA_STACK_ROOT/.venv/bin/python" -m pytest provider/tests \
  --cov=vektra_lms_moodle --cov-config=provider/pyproject.toml --cov-fail-under=95
```

The run covers the unit tests, the conformance kit on the 328 recorded responses
(`tests/fixtures/moodle_ws/`) and the joint suites. The live suites (`-m moodle`) need the seeded
Moodle 4.3 / 5.2 dev sites of the vektra-moodle repository (`docker/`) and the variables
`VEKTRA_ASSESS_MOODLE43_URL`, `VEKTRA_ASSESS_MOODLE52_URL`, `VEKTRA_ASSESS_MOODLE_MANIFEST_DIR`,
`VEKTRA_ASSESS_MOODLE_TOKEN_DIR`; without them they skip as BLOCKED.

## CI and releases

- `.github/workflows/provider.yml`: the tests above on every change to `provider/`, with a 95%
  coverage gate. It fails if the conformance kit or the joint suites were skipped.
- `.github/workflows/provider-live.yml`: weekly and on demand, the live suites against freshly
  built Moodle 4.3 and 5.2 sites.
- `.github/workflows/provider-release.yml`: a `provider-v<version>` tag, where `<version>` must
  equal `version` in `pyproject.toml`, runs the tests, builds the wheel and publishes it with its
  sha256 as a GitHub release. The release notes name the tested vektra-stack commit.

Versioning: wheel `X.Y.Z` is tested against vektra-stack tag `vX.Y.Z`. Until the first vektra-stack
release that carries the LMS Protocol, the wheels are pre-releases (`0.9.0.devN`) tested against
the pinned develop commit.
