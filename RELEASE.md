# Release steps

## 1. Create a release branch

1. Before merging a feature, create a release branch for the next target version, e.g.

   ```bash
   git checkout trunk
   git checkout -b planned-release/0.2.1
   ```

2. In GitHub, select the base branch as the `planned-release/...` branch.
3. Merge feature branches into the `planned-release/...` branch.

## 2. Update build files

```bash
npm run build
composer install --no-dev
```

Note: If new production dependencies have been added, modify the root `.gitignore` file to include new `vendor/` subfolders.

Now commit these build changes in.

## 3. Bump plugin version

1. When the version is ready for release, inside the `planned-release/...` branch, bump the version number in `vip-workflow.php`. Change plugin header and `VIP_WORKFLOW_VERSION` to match new version.
2. In `package.json`, also bump the `version` field to match, and run `npm install` to update `package-lock.json`.
3. Commit the changed files to the `planned-release/...` branch.
4. Create a PR for the planned release branch (e.g. "Planned release 0.2.1") and merge to `trunk`.

## 4. Tag branch for release

1. In `trunk`, add a tag for the release:

   ```bash
   git checkout trunk
   git pull
   git tag -s -a <version> -m "Release <version>"

   # e.g. git tag -s -a 1.0.2 -m "Release 1.0.2"
   ```

2. Run `git push --tags`.

## 5. Create a release

1. In the `vip-workflow` folder, run this command to create a plugin ZIP:

   ```bash
   git archive --prefix "vip-workflow/" <version> -o vip-workflow-<version>.zip

   # e.g. git archive --prefix "vip-workflow/" 1.0.2 -o vip-workflow-1.0.2.zip
   #
   # Creates a ZIP archive with the prefix folder "vip-workflow/" containing files from tag 1.0.2
   ```

2. Visit the [vip-workflow-plugin create release page](https://github.com/automattic/vip-workflow-plugin/releases/new).
3. Select the newly created version tag in the dropdown.
4. For the title, enter the release version name (e.g. `1.0.2`)
5. Add a description of release changes.
6. Attach the plugin ZIP.
7. Click "Publish release."
