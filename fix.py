import os
import re

def main():
    base_dir = r"c:\Users\grass\Documents\Symcon\SymconSmartClimate"
    
    # 1. Fix BasementClimate
    file_path = os.path.join(base_dir, "BasementClimate", "module.php")
    with open(file_path, "r", encoding="utf-8") as f:
        content = f.read()

    # Fix 1: RegisterVariable calls passing ""
    content = re.sub(r'\$this->RegisterVariable(Float|Integer|Boolean|String)\("([^"]+)", "([^"]+)", ""\);',
                     r'$this->RegisterVariable\1("\2", "\3", [\n            \'PRESENTATION\' => VARIABLE_PRESENTATION_VALUE_PRESENTATION\n        ]);', content)
    
    # Cleanup duplicate presentations created if we just replaced ""
    # Actually wait, `IPS_SetVariableCustomPresentation($this->GetIDForIdent('DewPointOutside'), [` ... is kept as is? Yes, user says "keep existing icon if present".
    # But wait, they also said: "All RegisterVariable calls passing "" as profile param: add ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => '...'] (keep existing icon if present)."
    
    # Let's do BasementClimate manually in python script
    
    pass

if __name__ == "__main__":
    main()
