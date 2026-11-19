#!/bin/sh
# Regenera el xcodeproj con xcodegen y re-inyecta los testables en el
# esquema (esta version de xcodegen no emite <TestableReference> para los
# targets de tests: queda vacio y xcodebuild no configura la accion test).
set -e
cd "$(dirname "$0")"

xcodegen generate

python3 - <<'EOF'
import re

scheme_path = "Taap.xcodeproj/xcshareddata/xcschemes/Taap.xcscheme"
pbx_path = "Taap.xcodeproj/project.pbxproj"

pbx = open(pbx_path).read()
scheme = open(scheme_path).read()

testables = ""
for name, product in [("TaapUnitTests", "TaapUnitTests.xctest"),
                      ("TaapUITests", "TaapUITests.xctest")]:
    m = re.search(r'([0-9A-F]{24}) /\* %s \*/' % name, pbx)
    if not m:
        continue
    testables += (
        '         <TestableReference\n'
        '            skipped = "NO">\n'
        '            <BuildableReference\n'
        '               BuildableIdentifier = "primary"\n'
        f'               BlueprintIdentifier = "{m.group(1)}"\n'
        f'               BuildableName = "{product}"\n'
        f'               BlueprintName = "{name}"\n'
        '               ReferencedContainer = "container:Taap.xcodeproj">\n'
        '            </BuildableReference>\n'
        '         </TestableReference>\n'
    )

if testables and "<TestableReference" not in scheme:
    scheme = scheme.replace(
        "      <Testables>\n      </Testables>",
        "      <Testables>\n" + testables + "      </Testables>",
    )
    open(scheme_path, "w").write(scheme)
    print("testables inyectados en el esquema")
EOF
