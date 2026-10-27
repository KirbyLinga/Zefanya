#!/usr/bin/env python3
import re

filepath = r'C:\Web\Zefanya\tests\Feature\PickupFailureTest.php'

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

lines = content.split('\n')
new_lines = []
i = 0

while i < len(lines):
    line = lines[i]
    
    # Remove duplicate use statements (only keep first occurrence)
    if line.startswith('use ') and not line.startswith('use App\\') and not line.startswith('use Illuminate\\') and not line.startswith('use Tests\\'):
        # This is a duplicate - skip it
        i += 1
        continue
    
    # Skip duplicate use App\Models\Rider; (already on line 6)
    if line.startswith('use App\\Models\\Rider;'):
        if any(l.startswith('use App\\Models\\Rider;') for l in new_lines):
            i += 1
            continue
    
    # Skip duplicate use Illuminate\Foundation\Testing\RefreshDatabase; (already on line 10)
    if line.startswith('use Illuminate\\Foundation\\Testing\\RefreshDatabase;'):
        if any(l.startswith('use Illuminate\\Foundation\\Testing\\RefreshDatabase;') for l in new_lines):
            i += 1
            continue
    
    # Skip duplicate use Tests\TestCase; (already on line 8)
    if line.startswith('use Tests\\TestCase;'):
        if any(l.startswith('use Tests\\TestCase;') for l in new_lines):
            i += 1
            continue
    
    # Convert it('name') to /** @test */ public function name()
    match = re.match(r"it\('([^']+)'\)", line)
    if match:
        test_name = match.group(1)
        new_lines.append('/** @test */')
        new_lines.append(f'public function {test_name}()')
        i += 1
        # Now collect the method body until we find the closing );
        body_lines = []
        method_closed = False
        while i < len(lines) and not method_closed:
            bl = lines[i]
            body_lines.append(bl)
            # Check if this line is the method closing );
            stripped = bl.strip()
            if stripped.endswith(');'):
                method_closed = True
            i += 1
        # Add the closing brace for the method
        new_lines.append('}')
        continue
    
    new_lines.append(line)
    i += 1

content = '\n'.join(new_lines)

# Now fix the method closings - replace }); with }
# Replace lines ending with }); with just }
content = re.sub(r'\n\s*\);)\s*$', '\n}', content)

# Ensure class closing is just }
# Find and fix the very last }); which closes the class
content = re.sub(r'\n\s*\);}$', '\n}', content)

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)

print('File fixed successfully')