# Epicyon Coding Style

Try to keep to the typical PEP8 coding style supported by Python static analysis systems.

Variables all lower case and using underscores to separate words (snake case).

Variables sent via webforms (with name="someVariableName") or within config.json are usually CamelCase, in order to clearly distinguish those from ordinary program variables.

Procedural style. Think "C style in Python". Avoid classes and objects as far as possible. This avoids *obfuscation via abstractions*. With procedural style everything is maximally obvious/concrete and can be followed through step by step without needing a lot of implicit background knowledge. Procedural style also makes more rigorous static analysis possible, to catch bugs before they happen at runtime. Mantra: "In the long run, obviousness beats clever abstractions".

Declare all called functions individually at the top of each module. This avoids any possible mistakes with colliding function names, and allows static analysis to explicitly check all dependencies.

Avoid too much encapsulation. Prefer passing a variable as a function argument rather than using "self.server.variable". This makes static checking of everything easier, before it goes into production.

Don't use any features of Python which are not supported by the version of Python within the current Debian stable release. Don't assume that all users are running the latest cutting-edge Python release.

Before doing a commit run all the unit tests. There are three layers of testing. The first just checks PEP8 compliance. The second runs a more thorough static analysis and unit tests. The third simulates instances communicating with each other.

```bash
./static_analysis
python3 epicyon.py --tests
python3 epicyon.py --testsnetwork
```
