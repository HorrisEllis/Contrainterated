# layers -- the Python instance's layering inside its one boundary (ledger
# M6, doc/SPEC.md). Port of the LAYER, EXTERNAL_REQUIRE and DYNAMIC_REQUIRE
# rules of lua/code/kit/lint.lua, with the layer map of
# lua/sisoTests/lint_config.lua as M6 states it for Python, plus the Python
# counterpart of its GLOBAL_WRITE / SHARED_TABLE_WRITE / RANDOM rules for
# the pure layer (and the state half of them for the observer).
#   python3 -B py/sisoTests/layers.test.py      (from the project root;
#                                               runnable from anywhere)
#
# Every .py file under py/code/siso/ is parsed with ast, so an import
# written in a comment, a string or a docstring never counts. Every Import
# and ImportFrom is extracted (relative ones resolved against the file's
# package), and every dynamic import (__import__, importlib.import_module,
# the importlib.util / importlib.machinery loaders, a loader's exec_module)
# is found; each is judged against the map:
#
#   layer      lives at (under py/code/siso/)   admits
#   core       core/                core; the pure standard library (DENY
#                                   below is not pure)
#   observer   observer/            observer; `time` (its clock: the log
#                                   stamps its entries, ledger R2's
#                                   counterpart)
#   contracts  contracts/           contracts; the standard library
#   kit        kit/                 kit, core, contracts, harness; the
#                                   standard library
#   fixtures   fixtures/            fixtures, core, observer, contracts,
#                                   kit, harness; the standard library
#   harness    harness.py           the standard library
#   package    __init__.py          its own subpackages, lazily (inside a
#                                   function: PEP 562 __getattr__), nothing
#                                   else
#   (`__future__` is a compiler directive and is admitted everywhere.)
#
# Findings, each reported as "<rule> <file> → <detail>" (file from the
# project root; detail the module, or the source text, and the line):
#   LAYER          an import the importing file's layer does not admit (a
#                  siso module of another layer, or a standard-library
#                  module the layer does not admit); LAYER
#   EAGER          the package importing at load time (outside a function)
#   EXTERNAL       a module that is neither siso nor in
#                  sys.stdlib_module_names; EXTERNAL_REQUIRE
#   DYNAMIC        a dynamic import whose argument is not a lone string
#                  literal naming a module the layer admits, a loader that
#                  takes a path or a spec (always), or a loader used as a
#                  value ("<name> used as a value", Lua's "require used as
#                  a value"); DYNAMIC_REQUIRE
#   UNRESOLVED     a siso module that names no file (siso.a.b is
#                  siso/a/b.py or siso/a/b/__init__.py; in `from P import
#                  n`, n is the submodule when its file exists, else a name
#                  of P)
#   OUTSIDE        a relative import that climbs above the siso package
#   UNKNOWN_LAYER  a .py file under siso/ in no layer (detail "-")
#   SYNTAX         ast.parse rejects the file (detail: why)
#  core/ and observer/ (state; Lua's GLOBAL_WRITE, SHARED_TABLE_WRITE):
#   GLOBAL_WRITE   a `global` statement anywhere (at module level, or in a
#                  function, where it is the one way to rebind module
#                  state), or `globals` read (the module's own dict)
#   SHARED_WRITE   a module-level name bound to a mutable container (a
#                  list, dict or set display or comprehension; list(),
#                  dict(), set(), bytearray(); collections.defaultdict,
#                  OrderedDict, deque, Counter, ChainMap; array.array)
#                  and later mutated anywhere in the module where the name
#                  is the module's: an item or attribute stored or deleted
#                  (N[k] = v, del N[k], N[k] += 1), a mutating method
#                  (N.append(...), N.update(...), ...), or N += ... at
#                  module level. Unlike the Lua lint, the load itself is
#                  checked too: a container the module needs is built
#                  whole (a comprehension) or frozen (tuple, frozenset).
#  core/ only (host; the observer may stamp with time, ledger R2):
#   HOST_CALL      print, open, input, exec, eval, compile, breakpoint, or
#                  __builtins__, named (called, or taken as a value);
#                  Lua's RANDOM is held by DENY (random, secrets, time,
#                  datetime, os)
#
# `nonlocal` is not a finding: it rebinds an enclosing function's variable,
# per-call closure state (the stream's own residue, a log's own
# propagation counter), never a module's (a `nonlocal` at module level is
# a SyntaxError).
#
# Ported as Python's counterpart (SUBSTRATE: there is no bytecode listing
# to read; the ast is the source's own parse, so strings and comments are
# never mistaken for code):
#   - LAYER: "x.*" admits a whole layer; the Lua kit's narrower core.event /
#     core.gate entry is M6's "kit: core" here.
#   - EXTERNAL_REQUIRE -> EXTERNAL, plus the host: the core's DENY list
#     below (M6: the core imports nothing from the host) and the observer's
#     `time` alone.
#   - DYNAMIC_REQUIRE -> DYNAMIC; "require used as a value" is kept.
#   - UNRESOLVED, OUTSIDE and EAGER are new: a Lua module name always maps
#     into source, a Python relative import need not, and Lua has no
#     package file.
# Advisory, with known blind spots (as the Lua lint's): an alias of a
# container or of a loader made by assignment (M = N; M.append(x)), a
# write through an element (N[k].append(x)) or through an unbound method
# (list.append(N, x)), a class attribute shared through instances, a
# closure made at load and kept in a module name, a builtin reached
# through getattr on something other than __builtins__, and a name
# shadowed in a class body or comprehension. A clean run is not proof;
# conformance cites the tests.
#
# The cases after the real tree run the same checker on small inline
# trees (a virtual py/code/siso/) to show each rule fires and each
# look-alike does not. Their sources spell the two keywords %I and %F
# (expanded by py()), because the census reads import statements by
# regex over the raw text of this file.
import ast
import os
import sys

sys.dont_write_bytecode = True  # no __pycache__ in the project tree
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.normpath(os.path.join(HERE, "..", "code")))

from siso import harness as H  # noqa: E402

SISO = os.path.normpath(os.path.join(HERE, "..", "code", "siso"))
SHOWN = "py/code/siso/"

# --------------------------------------------------------------------------
# the map (M6)

LAYERS = (
    # name, path under siso/ (a directory ends in "/"), siso layers admitted
    ("core", "core/", ("core",)),
    ("observer", "observer/", ("observer",)),
    ("contracts", "contracts/", ("contracts",)),
    ("kit", "kit/", ("kit", "core", "contracts", "harness")),
    ("fixtures", "fixtures/", ("fixtures", "core", "observer", "contracts", "kit", "harness")),
    ("harness", "harness.py", ()),
    ("package", "__init__.py", ("core", "observer", "contracts", "kit", "fixtures", "harness")),
)
ADMITS = {name: admits for name, _, admits in LAYERS}

# The standard library each layer may import: "all", "pure" (all but
# DENY and the private C modules) or a tuple of top-level names.
STDLIB = {
    "core": "pure",
    "observer": ("time",),
    "contracts": "all",
    "kit": "all",
    "fixtures": "all",
    "harness": "all",
    "package": (),
}

# The standard-library modules that are not pure: the core may not import
# them (top-level name; a submodule such as os.path or importlib.util is
# its package's). Each reason is the door it opens.
DENY = {
    # the phase's list
    "os": "files, processes, the environment and os.urandom",
    "sys": "the interpreter's state: argv, stdio, sys.modules, exit",
    "io": "streams and files",
    "subprocess": "processes",
    "socket": "the network",
    "pathlib": "the file system",
    "shutil": "the file system",
    "tempfile": "the file system",
    "time": "the clock (the observer's alone, ledger R2)",
    "datetime": "the clock (now, today)",
    "random": "nondeterminism (the Lua lint's RANDOM)",
    "secrets": "the operating system's randomness",
    "threading": "threads: concurrency and state shared across them",
    "multiprocessing": "processes and shared memory",
    "asyncio": "an event loop: scheduling, I/O and the clock",
    "signal": "process signals",
    "ctypes": "raw memory and foreign code",
    "gc": "the collector: every live object (get_objects, get_referrers)",
    "inspect": "frames and source files: the callers' state",
    "importlib": "modules loaded by name or path at run time (DYNAMIC)",
    "builtins": "print, open, input and __import__, and rebinding them",
    "pickle": "loading arbitrary objects and code",
    "logging": "output, handlers and the global logger registry",
    "warnings": "output and the global filter registry",
    # the same doors, by other modules
    "_thread": "threads (threading's own primitive)",
    "concurrent": "threads and processes (concurrent.futures)",
    "select": "waiting on I/O",
    "selectors": "waiting on I/O",
    "urllib": "the network",
    "http": "the network",
    "ssl": "the network",
    "mmap": "memory-mapped files",
    "fcntl": "file descriptors",
    "glob": "the file system",
    "fileinput": "files and stdin",
    "linecache": "source files",
    "traceback": "source files and output",
    "sqlite3": "persistence",
    "shelve": "persistence",
    "dbm": "persistence",
    "marshal": "loading code",
    "runpy": "running modules and paths",
    "pkgutil": "module discovery and loading",
    "zipimport": "loading code",
    "site": "the installation's paths",
    "sysconfig": "the installation's paths",
    "platform": "the host's identity",
    "getpass": "the user and the terminal",
    "uuid": "the clock, the host's address and randomness",
    "locale": "process-global locale state",
    "zoneinfo": "the time-zone database files",
    "atexit": "process exit hooks",
    "faulthandler": "process-level hooks and output",
    "tracemalloc": "process-level allocation tracing",
    "weakref": "the collector's timing",
    "sched": "the clock",
    "webbrowser": "launching programs",
    "posix": "the operating system (os's own primitive)",
    "nt": "the operating system (os's own primitive)",
    "pwd": "the user database",
    "grp": "the group database",
    "resource": "process limits",
}
# Private C modules (_io, _socket, _posixsubprocess, _random, ...) are the
# implementations behind the public ones: the core imports none of them.

HOST_NAMES = {
    "print": "output",
    "open": "files",
    "input": "the terminal",
    "exec": "code built from strings",
    "eval": "code built from strings",
    "compile": "code built from strings",
    "breakpoint": "the debugger",
    "__builtins__": "every builtin, by name",
}

# the loaders: a lone string literal names what they load
NAME_LOADERS = {
    "builtins.__import__",
    "importlib.__import__",
    "importlib.import_module",
    "importlib.util.find_spec",
    "runpy.run_module",
}
# the loaders that take a path, a spec or a module: always dynamic
PATH_LOADERS = {
    "importlib.reload",
    "importlib.util.spec_from_file_location",
    "importlib.util.spec_from_loader",
    "importlib.util.module_from_spec",
    "importlib.util.LazyLoader",
    "importlib.machinery.SourceFileLoader",
    "importlib.machinery.SourcelessFileLoader",
    "importlib.machinery.ExtensionFileLoader",
    "importlib.machinery.FileFinder",
    "importlib.machinery.PathFinder",
    "importlib.machinery.ModuleSpec",
    "importlib.machinery.BuiltinImporter",
    "importlib.machinery.FrozenImporter",
    "runpy.run_path",
    "zipimport.zipimporter",
}
LOADER_METHODS = {"exec_module", "load_module"}

CONTAINER_BUILTINS = {"list", "dict", "set", "bytearray"}
CONTAINER_CALLS = {
    "collections.defaultdict",
    "collections.OrderedDict",
    "collections.deque",
    "collections.Counter",
    "collections.ChainMap",
    "array.array",
}
MUTATORS = {
    "append", "extend", "insert", "pop", "remove", "clear", "update", "setdefault", "popitem",
    "add", "discard", "sort", "reverse", "difference_update", "intersection_update",
    "symmetric_difference_update", "appendleft", "extendleft", "popleft", "rotate", "subtract",
    "move_to_end", "__setitem__", "__delitem__", "__iadd__", "__ior__", "__iand__", "__ixor__",
    "__isub__", "__imul__",
}

STATE_LAYERS = ("core", "observer")
HOST_LAYERS = ("core",)

FUNCS = (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda)


def layerOf(rel):
    """The layer of a path relative to siso/, or None."""
    for name, path, _ in LAYERS:
        if (rel.startswith(path) if path.endswith("/") else rel == path):
            return name
    return None


def packageOf(rel):
    """The package a file's relative imports resolve against."""
    parts = ["siso"] + rel.split("/")[:-1]
    return ".".join(parts)


def fileOf(mod, files):
    """The file (relative to siso/) a siso module names, or None."""
    parts = mod.split(".")[1:]
    if not parts:
        return "__init__.py" if "__init__.py" in files else None
    base = "/".join(parts)
    for c in (base + ".py", base + "/__init__.py"):
        if c in files:
            return c
    return None


def stdlibAdmits(layer, top):
    policy = STDLIB[layer]
    if policy == "all":
        return True
    if policy == "pure":
        return top not in DENY and not top.startswith("_")
    return top in policy


# --------------------------------------------------------------------------
# extraction

def lineOf(detail):
    """The line a finding's detail ends with ("... (line N)")."""
    return int(detail.rsplit("line ", 1)[1].rstrip(")"))


def aliasesOf(tree):
    """Local name -> the dotted name it stands for (imports only)."""
    out = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for a in node.names:
                if a.asname:
                    out[a.asname] = a.name
                else:
                    top = a.name.split(".")[0]
                    out[top] = top
        elif isinstance(node, ast.ImportFrom) and node.level == 0 and node.module:
            for a in node.names:
                if a.name != "*":
                    out[a.asname or a.name] = node.module + "." + a.name
    return out


def qualify(node, aliases):
    """The dotted name an expression stands for, or None."""
    if isinstance(node, ast.Name):
        if node.id in aliases:
            return aliases[node.id]
        if node.id == "__import__":
            return "builtins.__import__"
        return None
    if isinstance(node, ast.Attribute):
        base = qualify(node.value, aliases)
        return base + "." + node.attr if base else None
    return None


def splitFunction(fn):
    """A function's parts: (run where it is defined, run when called).
    Decorators, defaults and annotations run at the definition."""
    a = fn.args
    outer = list(getattr(fn, "decorator_list", ()))
    outer += list(a.defaults) + [d for d in a.kw_defaults if d is not None]
    for arg in a.posonlyargs + a.args + a.kwonlyargs + [x for x in (a.vararg, a.kwarg) if x]:
        if arg.annotation is not None:
            outer.append(arg.annotation)
    if getattr(fn, "returns", None) is not None:
        outer.append(fn.returns)
    inner = fn.body if isinstance(fn.body, list) else [fn.body]
    return outer, inner


def withEager(tree):
    """Each node with whether it runs at load (outside any function)."""
    out = []
    stack = [(tree, True)]
    while stack:
        node, eager = stack.pop()
        out.append((node, eager))
        if isinstance(node, FUNCS):
            outer, inner = splitFunction(node)
            stack.extend((c, eager) for c in outer)
            stack.extend((c, False) for c in inner)
        else:
            stack.extend((c, eager) for c in ast.iter_child_nodes(node))
    return out


def extract(src, rel="core/x.py"):
    """source -> {"imports": [(module, line, eager)], "problems":
    [(rule, detail)], "dynamics": [(call text, module or None, line, eager)]}.
    Raises SyntaxError when the source cannot be parsed."""
    tree = ast.parse(src)
    aliases = aliasesOf(tree)
    imports, problems, dynamics = [], [], []
    called = set()
    nodes = withEager(tree)
    for node, eager in nodes:
        line = getattr(node, "lineno", 0)
        if isinstance(node, ast.Import):
            for a in node.names:
                imports.append((a.name, line, eager))
        elif isinstance(node, ast.ImportFrom):
            if node.level:
                pkg = packageOf(rel).split(".")
                if node.level > len(pkg):
                    problems.append(("OUTSIDE", "." * node.level + (node.module or "") + " (line %d)" % line))
                    continue
                base = ".".join(pkg[: len(pkg) - node.level + 1] + ([node.module] if node.module else []))
            else:
                base = node.module
            imports.append((base, line, eager, [a.name for a in node.names if a.name != "*"]))
        elif isinstance(node, ast.Call):
            q = qualify(node.func, aliases)
            method = isinstance(node.func, ast.Attribute) and node.func.attr in LOADER_METHODS
            if q in NAME_LOADERS or q in PATH_LOADERS or method:
                called.add(id(node.func))
                literal = None
                if (
                    q in NAME_LOADERS
                    and len(node.args) == 1
                    and not node.keywords
                    and isinstance(node.args[0], ast.Constant)
                    and type(node.args[0].value) is str
                    and node.args[0].value
                    and not node.args[0].value.startswith(".")
                ):
                    literal = node.args[0].value
                dynamics.append((ast.unparse(node), literal, line, eager))
    for node, eager in nodes:
        if isinstance(node, (ast.Name, ast.Attribute)) and isinstance(node.ctx, ast.Load) and id(node) not in called:
            q = qualify(node, aliases)
            if q in NAME_LOADERS or q in PATH_LOADERS:
                problems.append(("DYNAMIC", "%s used as a value (line %d)" % (ast.unparse(node), node.lineno)))
    imports.sort(key=lambda t: t[1])
    dynamics.sort(key=lambda t: t[2])
    problems.sort(key=lambda t: lineOf(t[1]))
    return {"tree": tree, "aliases": aliases, "imports": imports, "problems": problems, "dynamics": dynamics}


# --------------------------------------------------------------------------
# state and host (core/, observer/)

def boundIn(fn):
    """The names a function binds locally (not declared global)."""
    names = set()
    a = fn.args
    for arg in a.posonlyargs + a.args + a.kwonlyargs + [x for x in (a.vararg, a.kwarg) if x]:
        names.add(arg.arg)
    declared = set()
    body = fn.body if isinstance(fn.body, list) else [fn.body]
    stack = list(body)
    while stack:
        node = stack.pop()
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
            names.add(node.name)
            continue
        if isinstance(node, ast.Lambda):
            continue
        if isinstance(node, ast.Name) and isinstance(node.ctx, (ast.Store, ast.Del)):
            names.add(node.id)
        elif isinstance(node, (ast.Import, ast.ImportFrom)):
            for al in node.names:
                names.add((al.asname or al.name).split(".")[0])
        elif isinstance(node, ast.ExceptHandler) and node.name:
            names.add(node.name)
        elif isinstance(node, ast.Global):
            declared.update(node.names)
        stack.extend(ast.iter_child_nodes(node))
    return names - declared, declared


def isContainer(expr, aliases):
    if isinstance(expr, (ast.List, ast.Dict, ast.Set, ast.ListComp, ast.DictComp, ast.SetComp)):
        return True
    if isinstance(expr, ast.Call):
        f = expr.func
        if isinstance(f, ast.Name) and f.id in CONTAINER_BUILTINS and f.id not in aliases:
            return True
        return qualify(f, aliases) in CONTAINER_CALLS
    return False


def moduleContainers(tree, aliases):
    """Module-level names bound to a mutable container -> line."""
    out = {}
    stack = list(tree.body)
    while stack:
        node = stack.pop()
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
            continue
        if isinstance(node, ast.Assign):
            for t in node.targets:
                if isinstance(t, ast.Name) and isContainer(node.value, aliases):
                    out.setdefault(t.id, node.lineno)
                elif (
                    isinstance(t, (ast.Tuple, ast.List))
                    and isinstance(node.value, (ast.Tuple, ast.List))
                    and len(t.elts) == len(node.value.elts)
                ):
                    for n, v in zip(t.elts, node.value.elts):
                        if isinstance(n, ast.Name) and isContainer(v, aliases):
                            out.setdefault(n.id, node.lineno)
        elif isinstance(node, ast.AnnAssign) and node.value is not None:
            if isinstance(node.target, ast.Name) and isContainer(node.value, aliases):
                out.setdefault(node.target.id, node.lineno)
        for field in ("body", "orelse", "finalbody", "handlers", "cases"):
            stack.extend(getattr(node, field, None) or [])
    return out


def stateFindings(tree, aliases, host):
    """(rule, detail) for GLOBAL_WRITE, SHARED_WRITE and (host) HOST_CALL."""
    out = []
    containers = moduleContainers(tree, aliases)

    def shared(name, shadow):
        return name in containers and name not in shadow

    def visit(node, shadow, atModule):
        if isinstance(node, FUNCS):
            local, declared = boundIn(node)
            inside = (shadow | local) - declared
            outer, inner = splitFunction(node)
            for child in outer:
                visit(child, shadow, atModule)
            for child in inner:
                visit(child, inside, False)
            return
        line = getattr(node, "lineno", 0)
        if isinstance(node, ast.Global):
            out.append(("GLOBAL_WRITE", "global %s (line %d)" % (", ".join(node.names), line)))
        elif isinstance(node, ast.Name) and isinstance(node.ctx, ast.Load):
            if node.id == "globals":
                out.append(("GLOBAL_WRITE", "globals (line %d)" % line))
            elif host and node.id in HOST_NAMES:
                out.append(("HOST_CALL", "%s (line %d)" % (node.id, line)))
        elif isinstance(node, (ast.Subscript, ast.Attribute)) and isinstance(node.ctx, (ast.Store, ast.Del)):
            if isinstance(node.value, ast.Name) and shared(node.value.id, shadow):
                out.append(("SHARED_WRITE", "%s (line %d)" % (ast.unparse(node), line)))
        elif isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute):
            f = node.func
            if f.attr in MUTATORS and isinstance(f.value, ast.Name) and shared(f.value.id, shadow):
                out.append(("SHARED_WRITE", "%s (line %d)" % (ast.unparse(f), line)))
        elif isinstance(node, ast.AugAssign) and isinstance(node.target, ast.Name):
            if atModule and shared(node.target.id, shadow):
                out.append(("SHARED_WRITE", "%s %s= (line %d)" % (node.target.id, _opText(node.op), line)))
        for child in ast.iter_child_nodes(node):
            visit(child, shadow, atModule)

    visit(tree, set(), True)
    out.sort(key=lambda t: lineOf(t[1]))
    return out


def _opText(op):
    return {
        ast.Add: "+", ast.Sub: "-", ast.Mult: "*", ast.BitOr: "|", ast.BitAnd: "&", ast.BitXor: "^",
        ast.MatMult: "@", ast.Div: "/", ast.FloorDiv: "//", ast.Mod: "%", ast.Pow: "**",
        ast.LShift: "<<", ast.RShift: ">>",
    }.get(type(op), "?")


# --------------------------------------------------------------------------
# the check

def judge(layer, mod, eager, files):
    """(rule, detail) for one imported module, or None when admitted."""
    top = mod.split(".")[0]
    if top == "siso":
        target = fileOf(mod, files)
        if target is None:
            return ("UNRESOLVED", mod)
        if layer is None:
            return None
        if layer == "package" and eager:
            return ("EAGER", mod)
        if layerOf(target) not in ADMITS[layer]:
            return ("LAYER", mod)
        return None
    if top == "__future__":
        return None
    if top in sys.stdlib_module_names:
        if layer is not None and not stdlibAdmits(layer, top):
            return ("LAYER", mod)
        return None
    return ("EXTERNAL", mod)


def check(tree):
    """tree: {path relative to siso/ ("/"-separated): source text or None}
    (every file; the .py ones are read) -> {"findings": ["<rule> <file> →
    <detail>"], "edges": imports judged, "files": .py files read,
    "stateFiles": files given the state rules}."""
    files = set(tree)
    findings = []
    edges = 0
    read = 0
    stateFiles = 0

    def add(rule, rel, detail):
        findings.append("%s %s%s → %s" % (rule, SHOWN, rel, detail))

    for rel in sorted(f for f in tree if f.endswith(".py")):
        read += 1
        layer = layerOf(rel)
        if layer is None:
            add("UNKNOWN_LAYER", rel, "-")
        try:
            found = extract(tree[rel], rel)
        except SyntaxError as e:
            add("SYNTAX", rel, "%s (line %s)" % (e.msg, e.lineno))
            continue
        for rule, detail in found["problems"]:
            add(rule, rel, detail)
        for imp in found["imports"]:
            mod, line, eager = imp[0], imp[1], imp[2]
            names = imp[3] if len(imp) > 3 else []
            targets = []
            if mod.split(".")[0] == "siso" and fileOf(mod, files) is not None:
                for n in names:
                    if fileOf(mod + "." + n, files) is not None:
                        targets.append(mod + "." + n)
                    elif mod not in targets:
                        targets.append(mod)
            if not targets:
                targets = [mod]
            for t in targets:
                edges += 1
                verdict = judge(layer, t, eager, files)
                if verdict:
                    add(verdict[0], rel, "%s (line %d)" % (verdict[1], line))
        for text, literal, line, eager in found["dynamics"]:
            edges += 1
            if literal is None or judge(layer, literal, eager, files) is not None:
                add("DYNAMIC", rel, "%s (line %d)" % (text, line))
        if layer in STATE_LAYERS:
            stateFiles += 1
            for rule, detail in stateFindings(found["tree"], found["aliases"], layer in HOST_LAYERS):
                add(rule, rel, detail)
    return {"findings": findings, "edges": edges, "files": read, "stateFiles": stateFiles}


def realTree():
    out = {}
    for dirpath, dirnames, filenames in os.walk(SISO):
        dirnames[:] = sorted(d for d in dirnames if d != "__pycache__")
        for f in filenames:
            p = os.path.join(dirpath, f)
            rel = os.path.relpath(p, SISO).replace(os.sep, "/")
            if f.endswith(".py"):
                with open(p, encoding="utf-8") as fh:
                    out[rel] = fh.read()
            else:
                out[rel] = None
    return out


# --------------------------------------------------------------------------
# inline trees

IMP = "imp" + "ort"
FRM = "fr" + "om"


def py(src):
    return src.replace("%I", IMP).replace("%F", FRM)


# one file per layer, importing nothing
BASE = {
    "__init__.py": "",
    "core/__init__.py": "",
    "core/event.py": "E = 1\n",
    "observer/__init__.py": "",
    "observer/streamlog.py": "S = 1\n",
    "contracts/__init__.py": "",
    "contracts/core_api.py": "C = 1\n",
    "kit/__init__.py": "",
    "kit/data.py": "D = 1\n",
    "fixtures/__init__.py": "",
    "fixtures/run.py": "R = 1\n",
    "harness.py": "H = 1\n",
}


def run(extra):
    t = dict(BASE)
    for k, v in extra.items():
        t[k] = py(v) if v is not None else None
    return check(t)["findings"]


def same(got, want, label=None):
    H.assertEqual(" | ".join(got), " | ".join(want), label)


def lines(*xs):
    return "\n".join(xs) + "\n"


# --------------------------------------------------------------------------
# the real tree

def realTreeClean():
    t = realTree()
    r = check(t)
    print("  layers: %d files, %d imports, %d findings" % (r["files"], r["edges"], len(r["findings"])))
    for f in r["findings"]:
        print("    " + f)
    H.assertEqual(len(r["findings"]), 0, "findings:\n    " + "\n    ".join(r["findings"]) + "\n  count")


H.test("the real tree: every .py file under py/code/siso/ is in one layer and imports only what its layer admits",
       realTreeClean)


def realTreeNotEmpty():
    t = realTree()
    pyFiles = [f for f in t if f.endswith(".py")]
    for name, _, _ in LAYERS:
        H.assert_(any(layerOf(f) == name for f in pyFiles), "no file in layer " + name)
    r = check(t)
    H.assert_(r["edges"] > 0, "no imports were read")
    H.assertEqual(r["stateFiles"], len([f for f in pyFiles if layerOf(f) in STATE_LAYERS]), "state files")
    H.assert_(r["stateFiles"] >= 2, "the state rules read no file")


H.test("the real tree is not empty: every layer has a file, imports were read, core/ and observer/ were checked",
       realTreeNotEmpty)


def denyListIsReal():
    for name, why in DENY.items():
        H.assert_(name in sys.stdlib_module_names, name + " is not a standard-library module")
        H.assert_(type(why) is str and why, name + " has no reason")
    for name in ("collections", "abc", "types", "math", "re", "json", "functools", "itertools"):
        H.assert_(stdlibAdmits("core", name), name + " should be pure")
    for name in ("_io", "_socket", "_random", "_posixsubprocess"):
        H.assert_(not stdlibAdmits("core", name), name + " should be denied")
    for name, why in HOST_NAMES.items():
        H.assert_(type(why) is str and why, name + " has no reason")


H.test("DENY names real standard-library modules, each with its reason; the pure ones the core uses stay admitted",
       denyListIsReal)


# --------------------------------------------------------------------------
# each rule fires

def layerCoreKit():
    same(run({"core/b.py": "%I siso.kit.data\n"}), ["LAYER py/code/siso/core/b.py → siso.kit.data (line 1)"])
    same(run({"core/b.py": "%F siso.kit %I data\n"}), ["LAYER py/code/siso/core/b.py → siso.kit.data (line 1)"])
    same(run({"core/b.py": "%F ..kit.data %I D\n"}), ["LAYER py/code/siso/core/b.py → siso.kit.data (line 1)"])
    same(run({"core/b.py": "%F siso %I harness\n"}), ["LAYER py/code/siso/core/b.py → siso.harness (line 1)"])
    same(run({"core/b.py": "%F siso %I Event\n"}), ["LAYER py/code/siso/core/b.py → siso (line 1)"])


H.test("LAYER: a core file importing the kit (absolute, from, relative), the harness, the package", layerCoreKit)


def layerHost():
    for mod in ("os", "sys", "time", "random", "importlib", "os.path", "importlib.util", "_io"):
        same(run({"core/b.py": "%I " + mod + "\n"}), ["LAYER py/code/siso/core/b.py → %s (line 1)" % mod], mod)
    same(run({"core/b.py": "%F datetime %I datetime\n"}), ["LAYER py/code/siso/core/b.py → datetime (line 1)"])
    same(run({"core/b.py": lines("%I collections.abc", "%I types", "%F math %I inf", "%F __future__ %I annotations")}),
         [])
    same(run({"observer/b.py": "%I time\n"}), [])
    same(run({"observer/b.py": "%I collections\n"}), ["LAYER py/code/siso/observer/b.py → collections (line 1)"])
    same(run({"kit/b.py": "%I os, subprocess\n"}), [])


H.test("LAYER: the core importing the host (DENY, a private C module), the observer anything but time", layerHost)


def matrix():
    imp = {
        "core": "siso.core.event",
        "observer": "siso.observer.streamlog",
        "contracts": "siso.contracts.core_api",
        "kit": "siso.kit.data",
        "fixtures": "siso.fixtures.run",
        "harness": "siso.harness",
        "package": "siso",
        "pure": "json",
        "host": "os",
        "clock": "time",
    }
    frm = {
        "core": "core/x.py",
        "observer": "observer/x.py",
        "contracts": "contracts/x.py",
        "kit": "kit/x.py",
        "fixtures": "fixtures/x.py",
        "harness": "harness.py",
        "package": "__init__.py",
    }
    admitted = {
        "core": ["core", "pure"],
        "observer": ["observer", "clock"],
        "contracts": ["contracts", "pure", "host", "clock"],
        "kit": ["kit", "core", "contracts", "harness", "pure", "host", "clock"],
        "fixtures": ["fixtures", "core", "observer", "contracts", "kit", "harness", "pure", "host", "clock"],
        "harness": ["pure", "host", "clock"],
        "package": ["core", "observer", "contracts", "kit", "fixtures", "harness"],
    }
    for layer, f in frm.items():
        for target, mod in imp.items():
            if layer == "package":
                src = "def __getattr__(name):\n    %I " + mod + "\n"
                line = 2
            else:
                src = "%I " + mod + "\n"
                line = 1
            want = [] if target in admitted[layer] else ["LAYER %s%s → %s (line %d)" % (SHOWN, f, mod, line)]
            same(run({f: src}), want, layer + " → " + target)


H.test("each layer admits exactly its map entry (M6)", matrix)


def eager():
    same(run({"__init__.py": "%I siso.core\n"}), ["EAGER py/code/siso/__init__.py → siso.core (line 1)"])
    same(run({"__init__.py": "class C:\n    %F siso.kit %I data\n"}),
         ["EAGER py/code/siso/__init__.py → siso.kit.data (line 2)"])
    same(run({"__init__.py": "def __getattr__(n):\n    %F siso %I core\n    return core\n"}), [])
    same(run({"__init__.py": "def f(x=__import__('siso.core')):\n    pass\n"}),
         ["DYNAMIC py/code/siso/__init__.py → __import__('siso.core') (line 1)"])
    same(run({"__init__.py": "def f():\n    %I json\n"}), ["LAYER py/code/siso/__init__.py → json (line 2)"])


H.test("EAGER: the package imports its subpackages only lazily (a default argument runs at load)", eager)


def relative():
    same(run({"core/b.py": lines("%F . %I event", "%F .event %I E", "%F siso.core %I event as ev")}), [])
    same(run({"core/__init__.py": "%F .event %I E\n"}), [])
    same(run({"core/b.py": "%F .. %I kit\n"}), ["LAYER py/code/siso/core/b.py → siso.kit (line 1)"])
    same(run({"core/b.py": "%F .. %I Event\n"}), ["LAYER py/code/siso/core/b.py → siso (line 1)"])
    same(run({"harness.py": "%F . %I kit\n"}), ["LAYER py/code/siso/harness.py → siso.kit (line 1)"])
    same(run({"core/b.py": "%F ... %I x\n"}), ["OUTSIDE py/code/siso/core/b.py → ... (line 1)"])
    same(run({"harness.py": "%F ..lua %I x\n"}), ["OUTSIDE py/code/siso/harness.py → ..lua (line 1)"])


H.test("relative imports resolve against the file's package; climbing above siso is OUTSIDE", relative)


def unresolved():
    same(run({"kit/b.py": "%I siso.nosuch\n"}), ["UNRESOLVED py/code/siso/kit/b.py → siso.nosuch (line 1)"])
    same(run({"kit/b.py": "%F .missing %I x\n"}), ["UNRESOLVED py/code/siso/kit/b.py → siso.kit.missing (line 1)"])
    same(run({"kit/b.py": "%F siso.core.event.deeper %I x\n"}),
         ["UNRESOLVED py/code/siso/kit/b.py → siso.core.event.deeper (line 1)"])
    # a name that is not a submodule is a name of the package (siso.core)
    same(run({"kit/b.py": "%F siso.core %I Event, event\n"}), [])
    same(run({"kit/b.py": "%I siso.kit\n"}), [])


H.test("UNRESOLVED: a siso module that names no file", unresolved)


def external():
    same(run({"kit/b.py": "%I numpy\n"}), ["EXTERNAL py/code/siso/kit/b.py → numpy (line 1)"])
    same(run({"core/b.py": "%F yaml.loader %I Loader\n"}), ["EXTERNAL py/code/siso/core/b.py → yaml.loader (line 1)"])
    same(run({"harness.py": "try:\n    %I pytest\nexcept ImportError:\n    pass\n"}),
         ["EXTERNAL py/code/siso/harness.py → pytest (line 2)"])
    same(run({"kit/b.py": "%I sisox\n"}), ["EXTERNAL py/code/siso/kit/b.py → sisox (line 1)"])


H.test("EXTERNAL: a module neither siso nor in sys.stdlib_module_names (guarded imports too)", external)


def dynamic():
    same(run({"kit/b.py": "def f(n):\n    return __import__(n)\n"}),
         ["DYNAMIC py/code/siso/kit/b.py → __import__(n) (line 2)"])
    same(run({"kit/b.py": lines("%I importlib", "def f(n):", "    return importlib.import_module('siso.' + n)")}),
         ["DYNAMIC py/code/siso/kit/b.py → importlib.import_module('siso.' + n) (line 3)"])
    same(run({"kit/b.py": lines("%F importlib %I import_module as im", "M = im(NAME)")}),
         ["DYNAMIC py/code/siso/kit/b.py → im(NAME) (line 2)"])
    same(run({"kit/b.py": lines("%I importlib.util as u", "s = u.spec_from_file_location('x', 'siso/core/event.py')",
                                "m = u.module_from_spec(s)", "s.loader.exec_module(m)")}),
         ["DYNAMIC py/code/siso/kit/b.py → u.spec_from_file_location('x', 'siso/core/event.py') (line 2)",
          "DYNAMIC py/code/siso/kit/b.py → u.module_from_spec(s) (line 3)",
          "DYNAMIC py/code/siso/kit/b.py → s.loader.exec_module(m) (line 4)"])
    same(run({"kit/b.py": lines("%I importlib", "load = importlib.import_module", "names = map(__import__, ['os'])")}),
         ["DYNAMIC py/code/siso/kit/b.py → importlib.import_module used as a value (line 2)",
          "DYNAMIC py/code/siso/kit/b.py → __import__ used as a value (line 3)"])
    # a lone literal naming an admitted module is an import like any other
    same(run({"kit/b.py": lines("%I importlib", "E = importlib.import_module('siso.core.event')",
                                "J = __import__('json')")}), [])
    same(run({"kit/b.py": lines("%I importlib", "S = importlib.import_module('siso.observer.streamlog')",
                                "N = importlib.import_module('siso.nosuch')", "R = importlib.import_module('.data')",
                                "F = __import__('siso.core', fromlist=['event'])")}),
         ["DYNAMIC py/code/siso/kit/b.py → importlib.import_module('siso.observer.streamlog') (line 2)",
          "DYNAMIC py/code/siso/kit/b.py → importlib.import_module('siso.nosuch') (line 3)",
          "DYNAMIC py/code/siso/kit/b.py → importlib.import_module('.data') (line 4)",
          "DYNAMIC py/code/siso/kit/b.py → __import__('siso.core', fromlist=['event']) (line 5)"])
    same(run({"core/b.py": "X = __import__('os')\n"}), ["DYNAMIC py/code/siso/core/b.py → __import__('os') (line 1)"])


H.test("DYNAMIC: a non-literal name, a path loader, exec_module, a loader as a value; a literal that is not admitted",
       dynamic)


def notInStrings():
    src = lines(
        '"""The module docstring.',
        "",
        "%I os",
        "%F siso.kit %I data",
        '"""',
        "# %I sys",
        "S = '%I socket'",
        'T = "%F siso.kit.data %I D"',
        "B = b'%I subprocess'",
        "F = f'{1} %I io'",
        "",
        "",
        "def f():",
        '    """%I pathlib',
        "    %F ..kit %I data",
        '    """',
        "    return '__import__(\"os\")'",
        "",
        "",
        "class C:",
        "    '''importlib.import_module(\"random\")'''",
        "    %F .event %I E  # the one real import",
    )
    found = extract(py(src), "core/b.py")
    H.assertEqual([(m, ln) for m, ln, *_ in found["imports"]], [("siso.core.event", 22)], "extracted")
    H.assertEqual(found["dynamics"], [], "dynamics")
    same(run({"core/b.py": src}), [], "findings")


H.test("an import written in a comment, a string, a bytes, an f-string or a docstring does not count", notInStrings)


def everyForm():
    src = lines(
        "%I a.b as c, d",
        "%F e.f %I (g,",
        "    h as i)",
        "%F . %I j, k",
        "%F .l %I *",
        "if TYPE_CHECKING:",
        "    %I m",
        "try:",
        "    %I n",
        "except ImportError:",
        "    n = None",
        "def o():",
        "    %I p",
        "    return lambda: __import__('q')",
        "class R:",
        "    %F s %I t",
        "with ctx():",
        "    %I u",
    )
    got = [(m, ln, e) for m, ln, e, *_ in extract(py(src), "kit/x.py")["imports"]]
    H.assertEqual(got, [("a.b", 1, True), ("d", 1, True), ("e.f", 2, True), ("siso.kit", 4, True),
                        ("siso.kit.l", 5, True), ("m", 7, True), ("n", 9, True), ("p", 13, False),
                        ("s", 16, True), ("u", 18, True)], "imports")
    dyn = [(t, lit, ln, e) for t, lit, ln, e in extract(py(src), "kit/x.py")["dynamics"]]
    H.assertEqual(dyn, [("__import__('q')", "q", 14, False)], "dynamics")


H.test("every import form is read: as, several, parenthesised, relative, star, guarded, in a function or class",
       everyForm)


def globalWrite():
    for layer in ("core", "observer"):
        f = layer + "/b.py"
        same(run({f: "_n = 0\n\ndef bump():\n    global _n\n    _n += 1\n"}),
             ["GLOBAL_WRITE py/code/siso/%s → global _n (line 4)" % f], layer)
        same(run({f: "global _m\n"}), ["GLOBAL_WRITE py/code/siso/%s → global _m (line 1)" % f], layer)
        same(run({f: "def f():\n    globals()['x'] = 1\n"}),
             ["GLOBAL_WRITE py/code/siso/%s → globals (line 2)" % f], layer)
        # nonlocal rebinds a closure's own variable, per call
        same(run({f: "def make():\n    n = 0\n    def inc():\n        nonlocal n\n        n += 1\n        return n\n"
                     "    return inc\n"}), [], layer + " nonlocal")
    same(run({"kit/b.py": "def f():\n    global _n\n    _n = 1\n"}), [], "kit")


H.test("GLOBAL_WRITE: a global statement or globals in core/ and observer/; nonlocal and the kit are not", globalWrite)


def sharedWrite():
    f = "core/b.py"
    S = "SHARED_WRITE py/code/siso/core/b.py → "
    same(run({f: "_SEEN = []\n\ndef see(x):\n    _SEEN.append(x)\n"}), [S + "_SEEN.append (line 4)"])
    same(run({f: "_M = {}\n\ndef put(k, v):\n    _M[k] = v\n\ndef drop(k):\n    del _M[k]\n"}),
         [S + "_M[k] (line 4)", S + "_M[k] (line 7)"])
    same(run({f: "_C = dict()\n\ndef inc(k):\n    _C[k] += 1\n"}), [S + "_C[k] (line 4)"])
    same(run({f: "_L = [1]\n_L += [2]\n_L.extend([3])\n"}), [S + "_L += (line 2)", S + "_L.extend (line 3)"])
    same(run({f: "%I collections\n_D = collections.defaultdict(list)\n\ndef f(k):\n    _D.setdefault(k, 1)\n"}),
         [S + "_D.setdefault (line 5)"])
    same(run({f: "%F collections %I deque\nQ, T = deque(), (1,)\n\ndef f():\n    Q.popleft()\n"}),
         [S + "Q.popleft (line 5)"])
    same(run({f: "_K = {k: 1 for k in 'ab'}\n\nclass A:\n    def m(self):\n        _K.update(a=2)\n"}),
         [S + "_K.update (line 5)"])
    same(run({"observer/b.py": "_X = set()\n\ndef f(v):\n    _X.add(v)\n"}),
         ["SHARED_WRITE py/code/siso/observer/b.py → _X.add (line 4)"])
    # not shared: rebound locally, a parameter, frozen, read only, not core/observer
    same(run({f: lines("_S = []", "def a():", "    _S = []", "    _S.append(1)", "def b(_S):", "    _S[0] = 1",
                       "def c():", "    for _S in ([],):", "        _S.append(1)",
                       "_T = (1, 2)", "_F = frozenset({1})", "def d():", "    return _S[0], _T, _F",
                       "_A = []", "def e():", "    _A[0].append(1)")}), [])
    same(run({"kit/b.py": "_SEEN = []\n\ndef see(x):\n    _SEEN.append(x)\n"}), [], "kit")
    # a nested function that declares the name global reaches the module's again
    same(run({f: lines("_Q = []", "def a(_Q):", "    def b():", "        global _Q", "        _Q.append(1)")}),
         ["GLOBAL_WRITE py/code/siso/core/b.py → global _Q (line 4)", S + "_Q.append (line 5)"])


H.test("SHARED_WRITE: a module-level mutable container mutated later; a local, a parameter or a frozen one is not",
       sharedWrite)


def hostCall():
    for name in ("print", "open", "input", "exec", "eval", "compile", "breakpoint", "__builtins__"):
        same(run({"core/b.py": "def f(x):\n    return %s(x)\n" % name}),
             ["HOST_CALL py/code/siso/core/b.py → %s (line 2)" % name], name)
    same(run({"core/b.py": "P = print\n"}), ["HOST_CALL py/code/siso/core/b.py → print (line 1)"])
    same(run({"core/b.py": "exec('%I os')\n"}), ["HOST_CALL py/code/siso/core/b.py → exec (line 1)"],
         "the string is not an import")
    same(run({"core/b.py": "def f(o):\n    o.print()\n    return o.open\n"}), [], "attributes")
    same(run({"observer/b.py": "def f(x):\n    print(x)\n"}), [], "observer")
    same(run({"kit/b.py": "def f(x):\n    print(open(x).read())\n"}), [], "kit")


H.test("HOST_CALL: print, open, input, exec, eval, compile, breakpoint named in core/ (not observer/, not kit/)",
       hostCall)


def unknownAndSyntax():
    same(run({"misc/m.py": "", "extra.py": "", "kit/notes.txt": None}),
         ["UNKNOWN_LAYER py/code/siso/extra.py → -", "UNKNOWN_LAYER py/code/siso/misc/m.py → -"])
    same(run({"misc/m.py": "%I numpy\n"}),
         ["UNKNOWN_LAYER py/code/siso/misc/m.py → -", "EXTERNAL py/code/siso/misc/m.py → numpy (line 1)"])
    got = run({"core/b.py": "def f(:\n"})
    H.assertEqual(len(got), 1, "one finding")
    H.assert_(got[0].startswith("SYNTAX py/code/siso/core/b.py → "), got[0])


H.test("UNKNOWN_LAYER and SYNTAX: a file in no layer, a file ast cannot parse", unknownAndSyntax)


H.finish("layers")
